<?php

namespace App\Livewire\Concerns;

use App\Models\Room;
use App\Models\RoomMember;
use App\Services\AntiCheatService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;

/**
 * Room read-model: a single read source for the whole multiplayer component.
 *
 * This is a BASE trait, not a layer on top of the others: forgetRoomCache() is called
 * from eleven methods across all clusters, so anything that mutates room/room_members
 * depends on it. Moving it here makes that dependency explicit.
 */
trait ReadsRoomState
{
    /**
     * Room + members + host, loaded once per request.
     *
     * #[Computed] is required here: this property is read from NINE different places
     * (orderedMembers, isHost, allReady, suddenDeathActive, suddenDeathRemaining,
     * raceStartsAt, raceStartsInMs, myXpResult, captureResultSnapshot) plus the view.
     * Old-style getters aren't cached by Livewire, so this eager-loading query would
     * re-run on every read -- in a component that polls during a race.
     *
     * The cache is dropped via forgetRoomCache() whenever this component mutates
     * room/room_members, so the render after an action doesn't use stale data.
     */
    #[Computed]
    public function roomData(): ?Room
    {
        if (
            ($this->step !== 'waiting' && $this->step !== 'racing')
            || empty($this->roomCode)
        ) {
            return null;
        }

        $room = Room::with(['members.user', 'host'])
            ->where('code', $this->roomCode)
            ->first();

        if (! $room) {
            $this->step = 'choose';

            return null;
        }

        return $room;
    }

    /** Drop the room cache after room/room_members change in this request. */
    private function forgetRoomCache(): void
    {
        unset($this->roomData, $this->leaderboardData);
    }

    /** Racers only, ordered (host first): fills the player slot grid. */
    public function getOrderedMembersProperty()
    {
        $room = $this->roomData;

        if (! $room) {
            return collect();
        }

        return $room->members
            ->where('role', RoomMember::ROLE_PLAYER)
            ->sortBy('id')
            ->sortByDesc(fn ($member) => $member->user_id === $room->host_id)
            ->values();
    }

    /** Spectators in this room (host-spectator first), for the list & badge. */
    public function getSpectatorsProperty()
    {
        $room = $this->roomData;

        if (! $room) {
            return collect();
        }

        return $room->members
            ->where('role', RoomMember::ROLE_SPECTATOR)
            ->sortByDesc(fn ($member) => $member->user_id === $room->host_id)
            ->sortBy('id')
            ->values();
    }

    public function getSpectatorCountProperty(): int
    {
        return $this->spectators->count();
    }

    /** True if the current user is a spectator in this room. */
    public function getIsSpectatorProperty(): bool
    {
        $room = $this->roomData;

        if (! $room) {
            return false;
        }

        return (bool) $room->members
            ->firstWhere('user_id', Auth::id())
            ?->isSpectator();
    }

    /**
     * The ordered race result board.
     *
     * ORDERED BY `place`, the column writeFinalStandings() already settled -- NOT by a
     * ranking of its own. It used to sort by wpm DESC, which made the result screen and
     * the permanent history disagree: they were two independent rankings that happened
     * to match only while every player finished (same text -> time and WPM move together).
     * A DNF breaks that, because the 999s sentinel replaces the finish time while the
     * accumulated wpm survives -- so someone who quit outranked a slower genuine finisher
     * on screen while their history said otherwise. Reading the settled column instead of
     * re-deriving one makes the two impossible to diverge.
     *
     * Rejected results carry place = null and sort last; the view renders a dash for them
     * rather than a number they never earned.
     *
     * DEPENDS ON finalization having run. Every caller satisfies that today: the sole
     * consumer is captureResultSnapshot(), reached only after finalizeRace() (fast-path
     * in updateRaceProgress, checkSuddenDeath, or roomUpdated reacting to the broadcast
     * those two send). Read this before finalization and place is null for everyone, so
     * the order collapses to the wpm tie-break.
     *
     * with('user') is required: this query RE-loads members (rather than reusing those
     * already eager-loaded in roomData), and captureResultSnapshot() reads $member->user
     * for each row -- without the eager load that's one query per player. Caught by
     * Model::preventLazyLoading(), not by eye.
     */
    #[Computed]
    public function leaderboardData()
    {
        if (! $this->roomData) {
            return collect();
        }

        // Racers only: the podium & result table don't include spectators.
        return $this->roomData->members()
            ->where('role', RoomMember::ROLE_PLAYER)
            ->with('user')
            ->orderByRaw('place IS NULL')
            ->orderBy('place', 'asc')
            ->orderBy('wpm', 'desc')
            ->get();
    }

    public function getStillInRoomUserIdsProperty(): array
    {
        $room = Room::where('code', $this->roomCode)->first();

        if (! $room) {
            return [];
        }

        return RoomMember::where('room_id', $room->id)->pluck('user_id')->all();
    }

    /**
     * EXP data for the match result panel: earned (room_members.xp_earned) + level
     * (the current user's levelData()). Null-safe for guests / before EXP is awarded.
     *
     * @return array{earned:int, level:array}|null
     */
    public function getMyXpResultProperty(): ?array
    {
        $user = Auth::user();
        if (! $user) {
            return null;
        }

        $earned = 0;
        if ($this->roomData) {
            $me = $this->roomData->members->firstWhere('user_id', $user->id);
            $earned = (int) ($me->xp_earned ?? 0);
        }

        return [
            'earned' => $earned,
            'level' => $user->levelData(),
        ];
    }

    /**
     * Why the CURRENT user's race result was rejected -- a lang key for the result
     * banner, or null if their result was recorded (or they aren't in the snapshot).
     *
     * Re-derived from the snapshot (wpm/accuracy/progress/duration) rather than stored:
     * no extra column/migration, and the reason can never drift from the actual
     * rejection rule in AntiCheatService. Only surfaced to the player themselves; other
     * players just see the generic "not counted" badge (no reason).
     */
    public function getMyRejectReasonProperty(): ?string
    {
        $me = collect($this->resultSnapshot)->firstWhere('user_id', Auth::id());

        // null result_recorded = not finalized; true = accepted. Only false is a rejection.
        if (! $me || ($me['result_recorded'] ?? null) !== false) {
            return null;
        }

        $progress = max(0, min(100, (int) ($me['progress_percent'] ?? 0)));
        $duration = (float) ($me['finished_time_seconds'] ?? 0);
        $correctChars = (int) round(($progress / 100) * mb_strlen($this->roomData?->text_to_type ?? ''));

        $reasons = app(AntiCheatService::class)
            ->raceResultReasons($correctChars, $duration, $progress, (float) ($me['accuracy'] ?? 0));

        // Map to a specific message, most-informative first. One reason wins the banner;
        // the rest still block recording but a single clear cause reads better.
        return match (true) {
            in_array('accuracy_progress_inconsistent', $reasons, true) => 'multiplayer.reject_accuracy',
            in_array('wpm_too_high', $reasons, true) => 'multiplayer.reject_wpm',
            in_array('char_count_inconsistent', $reasons, true) => 'multiplayer.reject_inconsistent',
            in_array('no_input', $reasons, true) => 'multiplayer.reject_empty',
            default => 'multiplayer.result_invalid', // fallback: rejected but no single mapped cause
        };
    }

    public function getIsHostProperty(): bool
    {
        $room = $this->roomData;

        return $room ? $room->host_id === Auth::id() : false;
    }

    public function getAllReadyProperty(): bool
    {
        $room = $this->roomData;

        if (! $room) {
            return false;
        }

        // Only non-host racers need to be ready. If the host is a spectator, they aren't a
        // racer, so all racers count -- every one of them must be ready.
        $participants = $room->members
            ->where('role', RoomMember::ROLE_PLAYER)
            ->where('user_id', '!=', $room->host_id);

        return $participants->count() > 0 && $participants->where('is_ready', false)->count() === 0;
    }

    /** True if sudden death is active (at least one player finished, room still racing). */
    public function getSuddenDeathActiveProperty(): bool
    {
        $room = $this->roomData;

        return (bool) ($room && $room->status === 'racing' && $room->countdown_started_at);
    }

    /** Sudden-death time left as a countdown in seconds (15 -> 0), not elapsed. */
    public function getSuddenDeathRemainingProperty(): int
    {
        $room = $this->roomData;

        if (! $room || ! $room->countdown_started_at) {
            return self::SUDDEN_DEATH_SECONDS;
        }

        $elapsed = now()->diffInSeconds($room->countdown_started_at, true);

        return max(0, self::SUDDEN_DEATH_SECONDS - (int) floor($elapsed));
    }

    /** Absolute time (ISO string) when the race officially starts, for a synced 3-2-1 countdown. */
    public function getRaceStartsAtProperty(): ?string
    {
        $room = $this->roomData;

        return $room && $room->race_starts_at
            ? $room->race_starts_at->toIso8601String()
            : null;
    }

    /**
     * Milliseconds left until start, computed by the SERVER at render time.
     *
     * This is deliberately not an absolute "server clock": comparing the server clock to
     * the client's Date.now() would count network latency as a clock difference, and
     * toIso8601String() truncates milliseconds (up to 1s of error). With a relative
     * duration, the client's clock & timezone no longer matter -- the client just counts
     * down this many ms from when the page was received.
     *
     * null if the race isn't scheduled yet.
     */
    public function getRaceStartsInMsProperty(): ?int
    {
        $room = $this->roomData;

        if (! $room || ! $room->race_starts_at) {
            return null;
        }

        // May be negative -> the race is past its start point (e.g. a player refreshed
        // mid-race); the client enters the race immediately without a countdown.
        return (int) round((float) now()->diffInMilliseconds($room->race_starts_at, false));
    }
}
