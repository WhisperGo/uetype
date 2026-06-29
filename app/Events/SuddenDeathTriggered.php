<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SuddenDeathTriggered implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomCode;
    public $endTimeIso;

    public function __construct($roomCode, $endTimeIso)
    {
        $this->roomCode = $roomCode;
        $this->endTimeIso = $endTimeIso; // Waktu masa tenggang berakhir dalam format ISO string
    }

    public function broadcastOn(): array
    {
        return [new Channel('race.' . $this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'race.sudden_death';
    }
}