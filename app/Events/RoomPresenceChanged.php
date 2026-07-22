<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Presence notification for a multiplayer room: someone joins ('join'), leaves
 * ('leave'), or is kicked by the host ('kick'). Rendered as a centered system message
 * in the chat panel rather than a normal bubble. Broadcast-only (not persisted),
 * reusing the already-subscribed 'room.{code}' channel — the frontend only needs one
 * extra listener ('.room.presence').
 */
class RoomPresenceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  'join'|'leave'|'kick'  $action */
    public function __construct(
        public string $roomCode,
        public string $username,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('room.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'room.presence';
    }

    public function broadcastWith(): array
    {
        return [
            'username' => $this->username,
            'action' => $this->action,
        ];
    }
}
