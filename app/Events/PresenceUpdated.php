<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Notifies a friend of a user's online/offline flip on 'friends.{friendId}'. */
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
