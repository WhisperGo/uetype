<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Notifies a friend of a user's online/offline flip on 'friends.{friendId}'. */
class PresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public int $friendId) {}

    /** Private: same channel as FriendshipUpdated and RoomInvitationSent, so it moves with them. */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('friends.'.$this->friendId)];
    }

    public function broadcastAs(): string
    {
        return 'presence.updated';
    }
}
