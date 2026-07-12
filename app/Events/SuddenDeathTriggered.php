<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Signals that a race has entered sudden-death, carrying the shared end time so
 * every client counts down to the same instant. Broadcasts now (no queue) so the
 * countdown stays in sync without a worker.
 */
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
