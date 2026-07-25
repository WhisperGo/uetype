<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
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

    public function broadcastOn(): array
    {
        return [new Channel('friends.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'room.invitation';
    }
}
