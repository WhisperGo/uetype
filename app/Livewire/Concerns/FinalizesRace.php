<?php

namespace App\Livewire\Concerns;

use App\Models\MultiplayerMatchHistory;
use App\Models\Room;
use App\Models\RoomMember;
use App\Services\AntiCheatService;
use Illuminate\Support\Facades\DB;

/**
 * Race finalization: assigns final placements & durations, validates them through
 * anti-cheat, and freezes the result board.
 *
 * Extracted because this is the ONLY cluster in MultiplayerLobby with a truly clean
 * boundary: it's only ever called INTO (from updateRaceProgress, giveUp, and
 * checkSuddenDeath) and calls nothing back except the read-model.
 *
 * Room-lifecycle and race-lifecycle are deliberately NOT split out: they're
 * interdependent (roomUpdated/render call resetToChoose, playAgain resets sudden-death
 * state), so splitting them would only yield mutually-using traits without adding
 * clarity.
 */
trait FinalizesRace
{
    /**
     * All in ONE transaction: a single race writes place & xp_earned for each player,
     * each user's total_xp, and one permanent history row per player. Failing midway
     * without a transaction leaves a half-finalized race -- and since the idempotency
     * guard `xp_earned IS NULL` treats already-processed players as done, re-calling
     * won't fix it.
     *
     * The idempotency guard is STILL needed: the transaction protects against partial
     * writes, the guard protects against double calls (the "all finished" fast-path and
     * checkSuddenDeath can both reach here). Different roles, not duplication.
     */
    public function finalizeRace(string $roomId): void
    {
        DB::transaction(fn () => $this->writeFinalStandings($roomId));

        // place/xp/result_recorded just changed -> snapshot & view must re-read. OUTSIDE
        // the transaction: this drops the in-memory cache, it doesn't write to the DB.
        $this->forgetRoomCache();
    }

    private function writeFinalStandings(string $roomId): void
    {
        $room = Room::find($roomId);
        $textLength = $room ? mb_strlen($room->text_to_type) : 0;

        // Only racers get finalized: spectators have no place/XP and must not pollute
        // the podium order or the player count in history.
        //
        // "Not finished" MUST be the first sort key. MySQL places NULL first on ASC, so
        // with finished_time_seconds as the primary key, unfinished players would land
        // above legitimate finishers -- 1st place for someone who never finished. The
        // three current callers of finalizeRace() guarantee no NULL reaches here (they
        // either wait for everyone to finish or set the DNF sentinel first), so this is
        // fragility, not a live bug -- but fragility that costs one line to prevent.
        $members = RoomMember::with('user')
            ->where('room_id', $roomId)
            ->where('role', RoomMember::ROLE_PLAYER)
            ->orderByRaw('finished_time_seconds IS NULL')
            ->orderBy('finished_time_seconds', 'asc')
            ->orderBy('progress_percent', 'desc')
            ->get();

        foreach ($members as $index => $member) {
            $place = $index + 1;
            $updateData = ['place' => $place];

            // EXP once per player: xp_earned null = not yet awarded (safe from double-award
            // via the "all finished" fast-path or checkSuddenDeath). rooms/room_members are
            // deleted once all players leave, so the permanent history row is written here
            // too -- the only point where all final columns (place, wpm, accuracy, xp) are
            // settled before the room can vanish.
            if (is_null($member->xp_earned) && $member->user) {
                // correctChars derived from progress% x text length (room_members doesn't
                // store the correct-char count), using the same formula as solo mode.
                $progress = max(0, min(100, (int) $member->progress_percent));
                $correctChars = (int) round(($progress / 100) * $textLength);

                // Same validity gate as solo mode: implausible results (impossible WPM,
                // inconsistent chars, impossible duration) are REJECTED -- not written to
                // history and no EXP, so the player's average WPM isn't corrupted.
                $isValid = $this->isValidRaceResult($member, $correctChars);
                $updateData['result_recorded'] = $isValid;

                if ($isValid) {
                    $xp = $member->user->addExp($correctChars, (float) $member->accuracy);
                    $updateData['xp_earned'] = $xp;

                    MultiplayerMatchHistory::create([
                        'user_id' => $member->user_id,
                        'room_code' => $room?->code ?? '',
                        'place' => $place,
                        'player_count' => $members->count(),
                        'wpm' => (int) $member->wpm,
                        'accuracy' => (float) $member->accuracy,
                        // The DNF sentinel (999) MUST NOT reach permanent history: here the
                        // column means "elapsed duration", and 999 would be read as a real
                        // duration by any stats that average it.
                        'finished_time_seconds' => $member->realFinishedSeconds(),
                        'dnf' => $member->isDnf(),
                        'xp_earned' => $xp,
                    ]);
                } else {
                    // Still mark xp_earned (0) so the idempotency guard above won't
                    // reprocess this player on the next finalizeRace call.
                    $updateData['xp_earned'] = 0;
                }
            }

            $member->update($updateData);
        }
    }

    /**
     * Start the sudden-death timer if it isn't running yet. Returns true ONLY on the
     * call that actually started it (that caller is the one that broadcasts).
     *
     * The condition is just one -- "timer not yet running" -- and deliberately does NOT
     * ask "am I the first player to finish". That second question used to be a condition,
     * and it was dangerous: the finish count is read BEFORE this player's finish status
     * is written, so if someone was already recorded as finished but the timer hadn't
     * started yet, the next player fails the "first" test -> the timer never starts ->
     * checkSuddenDeath() always returns -> the remaining players hang forever.
     *
     * The conditional `whereNull(...)` update makes it atomic: of two parallel requests,
     * only one gets affected-rows = 1, so the timer can't be reset by the second player.
     * Same pattern as TypingEngine::attachToWarClaim().
     */
    private function startSuddenDeathIfNeeded(Room $room): bool
    {
        $claimed = Room::where('id', $room->id)
            ->whereNull('countdown_started_at')
            ->update(['countdown_started_at' => now()]);

        if (! $claimed) {
            return false;
        }

        // Reload so the caller can read the fresh countdown_started_at (used to compute
        // the broadcast deadline).
        $room->refresh();

        return true;
    }

    /**
     * Server-side validity gate for a finished race result, reusing AntiCheatService.
     * Only genuine cheat signals reject (impossible WPM / inconsistent chars) --
     * NOT low throughput/short duration, which are normal for a DNF or slow finish
     * (those stay recorded, matching the "anti-cheat only" rule). totalChars ==
     * correctChars because race progress only advances on correct characters.
     */
    private function isValidRaceResult(RoomMember $member, int $correctChars): bool
    {
        $duration = (float) ($member->finished_time_seconds ?? 0);

        $antiCheat = app(AntiCheatService::class);
        $reasons = $antiCheat->check($correctChars, $correctChars, $duration)['reasons'];

        // The list of "impossible" signals lives in AntiCheatService, not copied here:
        // one definition of cheating, used by both race and solo.
        return ! $antiCheat->isCheating($reasons);
    }

    private function captureResultSnapshot(): void
    {
        if (! empty($this->resultSnapshot) || ! $this->roomData) {
            return;
        }

        $this->resultSnapshot = $this->leaderboardData->map(fn ($member) => [
            'user_id' => $member->user_id,
            'username' => $member->user->username,
            'avatar' => $member->user->avatar,
            'wpm' => (int) $member->wpm,
            'accuracy' => $member->accuracy,
            'finished_time_seconds' => $member->finished_time_seconds,
            'place' => $member->place,
            // false = rejected by anti-cheat (excluded from stats); null = not yet finalized.
            'result_recorded' => $member->result_recorded,
        ])->values()->all();
    }
}
