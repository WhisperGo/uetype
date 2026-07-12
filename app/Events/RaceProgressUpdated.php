<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Streams one player's live race position (progress, wpm, finished) to the other
 * racers on 'race.{roomCode}', so opponents' mascots move without a server round-trip.
 */
class RaceProgressUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomCode;

    public $userId;

    public $progressData;

    public function __construct($roomCode, $userId, $progressData)
    {
        $this->roomCode = $roomCode;
        $this->userId = $userId;
        $this->progressData = $progressData; // progress_percent, wpm, accuracy
    }

    public function broadcastOn(): array
    {
        return [new Channel('race.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'race.progress';
    }
}
