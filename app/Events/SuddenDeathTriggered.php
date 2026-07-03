<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

// ShouldBroadcastNow (bukan ShouldBroadcast): dikirim langsung tanpa antre queue,
// supaya sinkronisasi hitung mundur sudden death instan tanpa perlu queue worker.
class SuddenDeathTriggered implements ShouldBroadcastNow
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
        return [new Channel('race.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'race.sudden_death';
    }
}
