<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A multiplayer room invitation, delivered to the invited friend on their already-subscribed
 * 'friends.{userId}' channel (the same channel toasts.js listens on for friend/presence
 * events -- no new subscription needed). The payload carries the room code (so the Accept
 * button can deep-link to /multiplayer?invite=CODE, where mount() auto-joins) plus the
 * inviter's name and avatar so the client can render a profile overlay without a round-trip.
 */
class RoomInvitationSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public int $userId;

    public string $roomCode;

    public string $inviterUsername;

    public ?string $inviterAvatar;

    public function __construct(int $userId, string $roomCode, string $inviterUsername, ?string $inviterAvatar = null)
    {
        $this->userId = $userId;
        $this->roomCode = $roomCode;
        $this->inviterUsername = $inviterUsername;
        $this->inviterAvatar = $inviterAvatar;
    }

    /**
     * PRIVATE, and this event is why 'friends.{userId}' had to become private at all.
     *
     * room.{code} and race.{code} are deliberately public, on the grounds that a 6-character
     * random code cannot be guessed. That argument only holds while the code stays secret --
     * and this payload carries it. Broadcast on a channel keyed by a sequential user id, it
     * handed out the very credential the room channels rely on, so anyone listening on
     * friends.7 could harvest codes and walk into the races behind them.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('friends.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'room.invitation';
    }
}
