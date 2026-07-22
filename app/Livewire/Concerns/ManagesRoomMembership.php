<?php

namespace App\Livewire\Concerns;

use App\Services\RoomMembershipService;

/**
 * Player movement between rooms: leave the old one, tend to what's left behind.
 *
 * The actual logic now lives in RoomMembershipService (shared with the leave-beacon /
 * leave-confirm HTTP endpoints). This trait is a thin adapter so createRoom()/joinRoom()
 * keep calling $this->departCurrentRooms(...) unchanged -- they invoke it INSIDE their
 * own transaction, so the raw (non-transactional) departCurrentRooms is exposed here
 * rather than the transaction-wrapping depart().
 */
trait ManagesRoomMembership
{
    /**
     * Remove $userId from whatever room they occupy (see RoomMembershipService). Kept as
     * a trait method because create/join call it from within their own transaction.
     */
    private function departCurrentRooms(int $userId, ?int $exceptRoomId = null): void
    {
        app(RoomMembershipService::class)->departCurrentRooms($userId, $exceptRoomId);
    }
}
