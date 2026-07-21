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
            'own' => $this->resolveOwn($viewerId),
            'friend' => $this->resolveFriend($refId, $viewerId),
            'leaderboard' => $this->resolveLeaderboard($refId, $mainMode, $subMode),
            default => null,
        };

        // Single gate: WPM must be positive, whatever the opponent type.
        if ($resolved === null || $resolved['wpm'] <= 0) {
            return null;
        }

        return ['type' => $type] + $resolved;
    }

    /** The player's own best record (mode-independent: highest_wpm). */
    private function resolveOwn(?int $viewerId): ?array
    {
        if ($viewerId === null) {
            return null;
        }

        $wpm = (float) (User::find($viewerId)?->highest_wpm ?? 0);

        return ['wpm' => $wpm, 'label' => 'Your Best'];
    }

    /**
     * A friend's best record (highest_wpm). refId = friendship_id, which MUST be an
     * accepted friendship involving the viewer -- prevents guessing other people's IDs.
     */
    private function resolveFriend(?int $refId, ?int $viewerId): ?array
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

        return ['wpm' => (float) $friend->highest_wpm, 'label' => $friend->username];
    }

    /**
     * A user's best record in the ACTIVE mode+config (MAX net_wpm). refId = user_id.
     * If the user has no record for that config -> null (fail-safe; ghost hidden).
     */
    private function resolveLeaderboard(?int $refId, string $mainMode, string $subMode): ?array
    {
        if ($refId === null) {
            return null;
        }

        $best = TypingResult::where('user_id', $refId)
            ->where('mode', $mainMode)
            ->where('mode_config', $subMode)
            ->max('net_wpm');

        if ($best === null) {
            return null;
        }

        $label = User::find($refId)?->username ?? 'Leaderboard';

        return ['wpm' => (float) $best, 'label' => $label];
    }
}
