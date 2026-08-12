<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A chat message inside a multiplayer room. Intentionally broadcast-only (never
 * persisted): lobby chat is ephemeral and disappears when the room dissolves, so no
 * table or migration is needed. Reuses the 'room.{code}' channel that race-echo.js
 * already subscribes to, so there is no new channel — the frontend only adds one listener.
 */
class RoomMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomCode,
        public int $senderId,
        public string $senderUsername,
        public string $body,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('room.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'room.message';
    }

    public function broadcastWith(): array
    {
        return [
            'senderId' => $this->senderId,
            'senderUsername' => $this->senderUsername,
            'body' => $this->body,
            'at' => now()->toIso8601String(),
        ];
    }
}
