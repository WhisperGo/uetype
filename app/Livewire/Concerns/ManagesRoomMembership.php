<?php

namespace App\Livewire\Concerns;

use App\Services\RoomMembershipService;

/**
 * Player movement between rooms: leave the old one, tend to what's left behind.
 *
 * The actual logic now lives in RoomMembershipService (shared with the leave-beacon /
 * leave-confirm HTTP endpoints). This trait is a thin adapter so createRoom()/joinRoom()
 * keep calling $this->departCurrentRooms(...) unchanged, rather than the fuller depart()
 * which also broadcasts -- create and join announce their own arrival instead.
 *
 * Both callers run it inside a transaction of their own because they have wider work to keep
 * atomic (leave-then-join must never leave the player in two rooms or none). That nests
 * safely: departCurrentRooms() opens its own transaction, and Laravel turns the inner one
 * into a savepoint. It used to be the callers that PROVIDED the only transaction, which made
 * the lockForUpdate inside settleAbandonedRoom() depend on every future caller remembering.
 */
trait ManagesRoomMembership
{
    /** Remove $userId from whatever room they occupy (see RoomMembershipService). */
    private function departCurrentRooms(int $userId, ?int $exceptRoomId = null): void
    {
        app(RoomMembershipService::class)->departCurrentRooms($userId, $exceptRoomId);
    }
}
