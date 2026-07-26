<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Signals sudden-death with the time LEFT, so every client counts down alike.
 *
 * The duration is what clients should use. An absolute end timestamp forces the receiver
 * to compare it against its own Date.now(), which turns any clock skew into a time
 * difference: a client running 15+ seconds fast computed "0 left" and locked itself out of
 * a race it still had a full window to finish. Same reasoning as the 3-2-1 countdown, which
 * already sends a relative duration (see ReadsRoomState::getRaceStartsInMsProperty).
 *
 * $endTimeIso is kept only as a fallback for client bundles still cached from before this
 * change; it can be dropped once those have rolled over.
 */
class SuddenDeathTriggered implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $roomCode;

    public $remainingSeconds;

    public $endTimeIso;

    public function __construct($roomCode, int $remainingSeconds, $endTimeIso)
    {
        $this->roomCode = $roomCode;
        $this->remainingSeconds = $remainingSeconds;
        $this->endTimeIso = $endTimeIso; // ISO string, legacy fallback
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
