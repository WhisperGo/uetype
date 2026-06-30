<?php

namespace App\Events;

use App\Models\Room;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

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
        // Menyiarkan event khusus ke channel berkode ruangan tersebut
        return [new Channel('room.' . $this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'room.updated';
    }
}