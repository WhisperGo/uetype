<?php

namespace App\Services;

use App\Events\RoomPresenceChanged;
use App\Events\RoomUpdated;
use App\Models\Room;
use App\Models\RoomMember;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\DB;

/**
 * The single door for removing a user from whatever room they occupy, and tending to
 * what's left behind: an empty room is deleted, an occupied one keeps a host that still
 * exists.
 *
 * This logic used to live as private methods on MultiplayerLobby (via the
 * ManagesRoomMembership trait). It's a service now because THREE callers need it:
 * the Livewire component (create/join/leave), and two HTTP endpoints
 * (MultiplayerPresenceController's leave-beacon and leave-confirm) that can't reach the
 * component's private methods. One definition, no drift.
 */
class RoomMembershipService
{
    /**
     * Remove $userId from every room they currently occupy (usually one), settling each.
     *
     * $exceptRoomId is skipped -- used by joinRoom() so entering the code of a room you
     * ALREADY occupy doesn't "leave then rejoin" (which, for a host, would hand the room
     * to someone else before you re-enter).
     *
     * Wrapped in a transaction: between deleting the old membership and settling the room
     * there must be no window where a concurrent read sees an inconsistent state.
     */
    public function departCurrentRooms(int $userId, ?int $exceptRoomId = null): void
    {
        $query = RoomMember::where('user_id', $userId)
            ->when($exceptRoomId, fn ($q) => $q->where('room_id', '!=', $exceptRoomId));

        $roomIds = (clone $query)->pluck('room_id')->unique();

        if ($roomIds->isEmpty()) {
            return;
        }

        $query->delete();

        foreach (Room::whereIn('id', $roomIds)->get() as $room) {
            $this->settleAbandonedRoom($room, $userId);
        }
    }

    /**
     * Full "leave the room" for a single user, including the broadcasts roommates need:
     * a "<user> left" chat notice (only while the room still has listeners) and a
     * RoomUpdated so the lobby re-renders the freed slot / host handoff.
     *
     * Returns true if the user actually was in a room (so callers can skip work if not).
     */
    public function depart(int $userId): bool
    {
        $member = RoomMember::where('user_id', $userId)->first();

        if (! $member) {
            return false;
        }

        // Capture identity BEFORE the row is deleted, so the leave notice can name them.
        $room = Room::find($member->room_id);
        $roomCode = $room?->code;
        $username = $member->user?->username ?? '';

        DB::transaction(fn () => $this->departCurrentRooms($userId));

        if ($roomCode === null) {
            return true;
        }

        // Leave notice only if someone is still there to hear it (the room is deleted once
        // its last member departs). Plain broadcast (no ->toOthers): callers are either a
        // controller with no socket, or a leaving component that resets to choose anyway.
        if (Room::where('id', $room->id)->exists()) {
            SafeBroadcast::run(fn () => broadcast(new RoomPresenceChanged($roomCode, $username, 'leave')));
        }

        SafeBroadcast::run(fn () => broadcast(new RoomUpdated($roomCode)));

        return true;
    }

    /**
     * Reap "stuck" memberships: a player who closed the tab / lost connection / walked
     * away WITHOUT pressing Leave. The leave-beacon only removes not-ready, non-host
     * members on a clean page unload; a ready player or host is deliberately kept so
     * mount() can restore them -- but if they never come back, their row lingers forever,
     * holding a slot and (as host) leaving a room no one can start or clean up.
     *
     * The signal is the site-wide presence heartbeat: while genuinely on any page the
     * client pings every ~30s, refreshing users.last_seen_at. So a member whose USER is
     * offline (User::isOnline() false -> last_seen_at stale past the 60s threshold, or
     * null after logout) is the one who is actually gone. This reads a maintained signal
     * rather than inventing a new heartbeat -- an idle-but-present waiter still pings, so
     * they are never wrongly swept.
     *
     * Two passes, because a room in each status fails in a different way:
     *
     *  - 'waiting'  -> reap stale members INDIVIDUALLY (a live lobby carries on around them).
     *  - 'racing'   -> never touch one competitor, since that corrupts placement; but if
     *                  EVERY member is gone the race can no longer close itself, so the whole
     *                  room goes. See sweepAbandonedRaces().
     *
     * Called lazily on lobby load (no scheduler), the same pattern ClanWarResolver uses.
     *
     * $exceptUserId is never swept even if momentarily stale -- the caller (the person
     * whose page just loaded) is provably present, and their own heartbeat may not have
     * landed yet on a fresh load.
     */
    public function sweepOfflineMembers(?int $exceptUserId = null): void
    {
        $this->sweepStaleWaitingMembers($exceptUserId);
        $this->sweepAbandonedRaces($exceptUserId);
        $this->sweepStaleFinishedRooms($exceptUserId);
    }

    /**
     * Delete FINISHED rooms nobody is looking at any more.
     *
     * These fell through a gap: the waiting sweep filters `status = 'waiting'` and the race
     * sweep filters `status = 'racing'`, so a room that reached 'finished' was swept by
     * neither. Everyone closing their tab on the result screen -- the single most ordinary way
     * a race ends -- therefore left the room and every one of its member rows behind forever,
     * and a player returning the next day was restored straight back into a stale result
     * modal.
     *
     * ALL-OR-NOTHING, the same rule as sweepAbandonedRaces() and for a related reason: the
     * result screen is still doing its job for as long as ONE person is there to read it, and
     * pulling individual rows out from under them would empty the board they are looking at.
     * Once nobody is left there is no board.
     *
     * Nothing is lost by deleting: finalizeRace() has already written the permanent
     * multiplayer_match_history rows and the XP by the time a room can reach this status.
     *
     * No broadcast, for the same reason as the race sweep: every member is gone by definition.
     */
    private function sweepStaleFinishedRooms(?int $exceptUserId = null): void
    {
        $cutoff = now()->subSeconds(User::ONLINE_THRESHOLD_SECONDS);

        $stale = Room::query()
            ->where('status', 'finished')
            ->whereDoesntHave('members', function ($member) use ($cutoff, $exceptUserId) {
                $member->whereHas('user', function ($user) use ($cutoff, $exceptUserId) {
                    $user->where(function ($present) use ($cutoff, $exceptUserId) {
                        $present->where('last_seen_at', '>=', $cutoff);

                        // The caller is provably here even before their first heartbeat lands.
                        if ($exceptUserId) {
                            $present->orWhere('id', $exceptUserId);
                        }
                    });
                });
            })
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        // room_members cascades on the foreign key, so deleting the room clears its rows.
        DB::transaction(fn () => Room::whereIn('id', $stale->pluck('id'))->delete());
    }

    /**
     * Delete races that every single member has walked away from.
     *
     * checkSuddenDeath() is only ever TRIGGERED by a client (lockRace() in race-arena.js);
     * the server is its gate, never its trigger. So when the last tab in a race closes,
     * nothing is left to close the race -- the room sits in 'racing' forever, out of reach
     * of the waiting-only sweep above, holding rows no one can ever clear.
     *
     * ALL-OR-NOTHING on purpose: a room keeps every member as long as ONE of them is still
     * present, because pulling an individual racer out mid-race would corrupt the finish
     * and placement accounting -- the same reason leave and kick are blocked once racing
     * starts. Once nobody is left, there is no placement left to corrupt.
     *
     * Deleted rather than finalized: nobody completed the race, so there are no standings
     * worth writing. Recording half-typed runs would drag real averages down, exactly the
     * reason a DNF never enters permanent history either.
     *
     * No broadcast: every member is gone by definition, and the caller is excluded below,
     * so there is nobody subscribed to tell.
     */
    private function sweepAbandonedRaces(?int $exceptUserId = null): void
    {
        $cutoff = now()->subSeconds(User::ONLINE_THRESHOLD_SECONDS);

        $abandoned = Room::query()
            ->where('status', 'racing')
            // "Has no member who is still here". A room with no members at all also matches,
            // which is correct -- that is orphan data with nothing left to protect.
            ->whereDoesntHave('members', function ($member) use ($cutoff, $exceptUserId) {
                $member->whereHas('user', function ($user) use ($cutoff, $exceptUserId) {
                    $user->where(function ($present) use ($cutoff, $exceptUserId) {
                        $present->where('last_seen_at', '>=', $cutoff);

                        // The caller's page is loading right now, so they are provably here
                        // even if their first heartbeat hasn't landed. Never sweep their race.
                        if ($exceptUserId) {
                            $present->orWhere('id', $exceptUserId);
                        }
                    });
                });
            })
            ->get();

        if ($abandoned->isEmpty()) {
            return;
        }

        // room_members cascades on the foreign key, so deleting the room clears its rows.
        DB::transaction(fn () => Room::whereIn('id', $abandoned->pluck('id'))->delete());
    }

    /** Reap stale members from WAITING rooms; see sweepOfflineMembers() for the rationale. */
    private function sweepStaleWaitingMembers(?int $exceptUserId = null): void
    {
        $cutoff = now()->subSeconds(User::ONLINE_THRESHOLD_SECONDS);

        // Candidate stale rows: members of waiting rooms whose user hasn't pinged since the
        // cutoff (or never -> null last_seen_at). Join to users so one query finds them all.
        $stale = RoomMember::query()
            ->join('rooms', 'rooms.id', '=', 'room_members.room_id')
            ->join('users', 'users.id', '=', 'room_members.user_id')
            ->where('rooms.status', 'waiting')
            ->when($exceptUserId, fn ($q) => $q->where('room_members.user_id', '!=', $exceptUserId))
            ->where(fn ($q) => $q->whereNull('users.last_seen_at')->orWhere('users.last_seen_at', '<', $cutoff))
            ->select('room_members.id', 'room_members.room_id', 'room_members.user_id')
            ->get();

        if ($stale->isEmpty()) {
            return;
        }

        // Group by room so each affected room is settled once, after all its stale members
        // are gone -- host handoff / delete-if-empty then reflects the final membership.
        $affectedRoomIds = [];

        DB::transaction(function () use ($stale, &$affectedRoomIds) {
            RoomMember::whereIn('id', $stale->pluck('id'))->delete();

            foreach ($stale->groupBy('room_id') as $roomId => $members) {
                $room = Room::find($roomId);

                if (! $room) {
                    continue;
                }

                // Pass one of the departed users so host handoff triggers if the host was
                // among the swept; settleAbandonedRoom re-checks host_id against it.
                $this->settleAbandonedRoom($room, (int) $members->first()->user_id);

                if (Room::whereKey($roomId)->exists()) {
                    $affectedRoomIds[$roomId] = $room->code;
                }
            }
        });

        // Tell the surviving members to re-render (freed slots, possible new host). Outside
        // the transaction: broadcasting is a side effect that shouldn't hold the DB lock.
        foreach ($affectedRoomIds as $code) {
            SafeBroadcast::run(fn () => broadcast(new RoomUpdated($code)));
        }
    }

    /**
     * A room just left by $leavingUserId: delete it if empty, otherwise make sure it
     * still has a host that exists.
     */
    private function settleAbandonedRoom(Room $room, int $leavingUserId): void
    {
        // lockForUpdate: two last members leaving at once must not both read "members
        // remain" and then have no one delete the room.
        $remaining = RoomMember::where('room_id', $room->id)->lockForUpdate()->count();

        if ($remaining === 0) {
            $room->delete();

            return;
        }

        $this->reassignHostIfNeeded($room, $leavingUserId);
    }

    /** If the leaver was the host, hand host to another member (racers preferred). */
    private function reassignHostIfNeeded(Room $room, int $leavingUserId): void
    {
        if ($room->host_id !== $leavingUserId) {
            return;
        }

        // Prefer a racer (those actually competing); only if none remains does a spectator
        // become a spectator-host.
        $newHost = RoomMember::where('room_id', $room->id)
            ->where('user_id', '!=', $leavingUserId)
            ->orderByRaw("role = '".RoomMember::ROLE_PLAYER."' DESC")
            ->orderBy('id', 'asc')
            ->first();

        if ($newHost) {
            $room->update(['host_id' => $newHost->user_id]);

            // is_ready only means something for racers; a spectator-host needn't be readied.
            if ($newHost->isPlayer()) {
                $newHost->update(['is_ready' => true]);
            }
        }
    }
}
