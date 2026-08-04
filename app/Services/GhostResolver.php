<?php

namespace App\Services;

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\TypingResult;
use App\Models\User;

/**
 * Derives a ghost opponent from an IDENTITY (type + refId), not from a WPM figure
 * sent by the client. THE single source of truth for deriving ghosts.
 *
 * Trust boundary: the client only supplies an identifier; WPM is always re-fetched
 * from the DB here -- used both when selecting (GhostPicker) AND when restoring a
 * saved choice (TypingEngine), so a rematch never freezes a stale figure and cannot
 * be forged through client state.
 *
 * This logic was previously duplicated in GhostPicker::selectOpponent() and
 * TypingEngine::resolveGhostDeepLink().
 */
class GhostResolver
{
    /**
     * @param  string  $type  'own' | 'friend' | 'leaderboard'
     * @param  int|null  $refId  friendship_id (friend) | user_id (leaderboard) | null (own)
     * @param  string  $mainMode  'time' | 'words' (ghost does not apply in survival)
     * @param  string  $subMode  the active mode's config
     * @param  int|null  $viewerId  the user currently playing
     * @return array{type: string, wpm: float, label: string}|null null if invalid / no record
     */
    public function resolve(string $type, ?int $refId, string $mainMode, string $subMode, ?int $viewerId): ?array
    {
        $resolved = match ($type) {
            'own' => $this->resolveOwn($viewerId, $mainMode, $subMode),
            'friend' => $this->resolveFriend($refId, $viewerId, $mainMode, $subMode),
            'leaderboard' => $this->resolveLeaderboard($refId, $mainMode, $subMode),
            default => null,
        };

        // Single gate: WPM must be positive, whatever the opponent type.
        if ($resolved === null || $resolved['wpm'] <= 0) {
            return null;
        }

        return ['type' => $type] + $resolved;
    }

    /**
     * The best Net WPM a user ever reached in THIS mode+config, or null if they have no
     * record there. Every opponent type funnels through here.
     *
     * A ghost is a pace to chase, and a pace only means anything against the same test.
     * 'own' and 'friend' used to read users.highest_wpm -- ONE cross-mode figure -- so
     * racing in time 120 paced you against a time 15 sprint nobody sustains for two
     * minutes, and a friend's ghost claimed a speed they may never have reached in that
     * mode at all. 'leaderboard' was already scoped this way; now all three agree.
     */
    private function bestWpmIn(?int $userId, string $mainMode, string $subMode): ?float
    {
        if ($userId === null) {
            return null;
        }

        return TypingResult::bestNetWpmFor($userId, $mainMode, $subMode);
    }

    /** The player's own record for the active mode+config. */
    private function resolveOwn(?int $viewerId, string $mainMode, string $subMode): ?array
    {
        $wpm = $this->bestWpmIn($viewerId, $mainMode, $subMode);

        return $wpm === null ? null : ['wpm' => $wpm, 'label' => 'Your Best'];
    }

    /**
     * A friend's record for the active mode+config. refId = friendship_id, which MUST be an
     * accepted friendship involving the viewer -- prevents guessing other people's IDs.
     */
    private function resolveFriend(?int $refId, ?int $viewerId, string $mainMode, string $subMode): ?array
    {
        if ($refId === null || $viewerId === null) {
            return null;
        }

        $friendship = Friendship::where('id', $refId)
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $viewerId)->orWhere('addressee_id', $viewerId))
            ->with(['requester', 'addressee'])
            ->first();

        if (! $friendship) {
            return null;
        }

        $friend = $friendship->requester_id === $viewerId
            ? $friendship->addressee
            : $friendship->requester;

        if (! $friend) {
            return null;
        }

        $wpm = $this->bestWpmIn($friend->id, $mainMode, $subMode);

        return $wpm === null ? null : ['wpm' => $wpm, 'label' => $friend->username];
    }

    /**
     * A user's record in the ACTIVE mode+config. refId = user_id.
     * If the user has no record for that config -> null (fail-safe; ghost hidden).
     *
     * Unlike 'own' and 'friend', this type takes a RAW user id straight from the client -- the
     * ?ghost=<id> deep link is a query parameter anyone can type. There is no ownership to
     * check here (a board is public by definition), so the boundary is a different one: the id
     * must belong to somebody the board actually lists. Without that, answering with a username
     * turned this into an id-to-username oracle over every account that had ever typed, which
     * is exactly what routing profiles by username is meant to prevent.
     */
    private function resolveLeaderboard(?int $refId, string $mainMode, string $subMode): ?array
    {
        if ($refId === null || ! $this->isPubliclyListed($refId)) {
            return null;
        }

        $wpm = $this->bestWpmIn($refId, $mainMode, $subMode);

        if ($wpm === null) {
            return null;
        }

        $label = User::find($refId)?->username ?? 'Leaderboard';

        return ['wpm' => $wpm, 'label' => $label];
    }

    /** Does this user clear the same eligibility gate the public board applies? */
    private function isPubliclyListed(int $userId): bool
    {
        return TypingResult::where('user_id', $userId)->leaderboardEligible()->exists();
    }
}
