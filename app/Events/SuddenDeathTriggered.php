<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Signals sudden-death with a shared end time so all clients count down alike. */
class SuddenDeathTriggered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomCode;

    public $endTimeIso;

    public function __construct($roomCode, $endTimeIso)
    {
        $this->roomCode = $roomCode;
        $this->endTimeIso = $endTimeIso; // ISO string
    }

    public function broadcastOn(): array
    {
        return [new Channel('race.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'race.sudden_death';
    }
}
