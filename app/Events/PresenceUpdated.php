<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifies each friend that a user's online/offline status changed, on their
 * 'friends.{friendId}' channel. Sent only on an actual status flip, not on
 * every heartbeat.
 */
class PresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $friendId) {}

    public function broadcastOn(): array
    {
        return [new Channel('friends.'.$this->friendId)];
    }

    public function broadcastAs(): string
    {
        return 'presence.updated';
    }
}
