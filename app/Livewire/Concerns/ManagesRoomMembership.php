<?php

namespace App\Livewire\Concerns;

use App\Models\Room;
use App\Models\RoomMember;

/**
 * Player movement between rooms: leave the old one, tend to what's left behind.
 *
 * This trait WRITES, and is therefore deliberately kept separate from ReadsRoomState
 * (pure read-model) -- merging them would blur which methods are safe to call inside
 * render.
 *
 * There's a reason: the "one player, at most one room" rule used to be honored only by
 * leaveRoom(). createRoom() deleted the user's room_members row but never checked whether
 * their old room became empty -- orphan `rooms` rows piled up forever. joinRoom() was
 * worse: it never dropped the old membership at all, so a player could be registered in
 * two rooms, locking a slot in an abandoned room and leaving behind an ownerless host.
 *
 * Now all three paths go through the same door.
 */
trait ManagesRoomMembership
{
    /**
     * Remove $userId from whatever room they currently occupy, then tend to each
     * abandoned room: empty -> deleted, still occupied -> host reassigned if the one who
     * left was the host.
     *
     * $exceptRoomId is excluded. This matters for joinRoom(): if the player enters the
     * code of a room they ALREADY occupy, without this exception they'd "leave" first --
     * and if they're the host, the room changes hands before they rejoin as a regular
     * member. Joining your own room must not make anyone lose host status.
     *
     * Called from inside a transaction (see createRoom/joinRoom): between deleting the old
     * membership and creating the new one there must be no window where the player is in
     * no room at all -- or, worse, in two.
     */
    private function departCurrentRooms(int $userId, ?int $exceptRoomId = null): void
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
     * A room just left by $leavingUserId: delete it if no members remain, otherwise
     * ensure it still has a host who actually exists.
     */
    private function settleAbandonedRoom(Room $room, int $leavingUserId): void
    {
        // lockForUpdate: the last two players leaving simultaneously must not both read
        // "members remain" and then have no one delete the room.
        $remaining = RoomMember::where('room_id', $room->id)->lockForUpdate()->count();

        if ($remaining === 0) {
            $room->delete();

            return;
        }

        $this->reassignHostIfNeeded($room, $leavingUserId);
    }
}
