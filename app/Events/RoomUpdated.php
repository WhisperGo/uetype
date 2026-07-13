<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Signals a room lifecycle change on 'room.{roomCode}' to re-render the lobby. */
class RoomUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomCode;

    public function __construct($roomCode)
    {
        $this->roomCode = $roomCode;
    }

    public function broadcastOn(): array
    {
        return [new Channel('room.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'room.updated';
    }
}
