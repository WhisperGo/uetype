<?php

namespace App\Services;

use App\Events\RoomPresenceChanged;
use App\Events\RoomUpdated;
use App\Models\Room;
use App\Models\RoomMember;
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
