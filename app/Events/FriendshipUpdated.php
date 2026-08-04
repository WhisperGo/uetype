<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Notifies a user of a friendship change (request/accept/reject/cancel/remove) on their channel 'friends.{userId}'. */
class FriendshipUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;

    public $notification;

    /**
     * @param  array|null  $notification  ['type' => 'request'|'accepted', 'message' => string]; null = refresh the UI without showing a toast.
     */
    public function __construct($userId, ?array $notification = null)
    {
        $this->userId = $userId;
        $this->notification = $notification;
    }

    /**
     * Private: this channel is shared with RoomInvitationSent (which carries a room code), so
     * the whole channel has to move together -- 'friends.7' and 'private-friends.7' are two
     * different channels as far as the broker is concerned. On its own merit it also stops a
     * stranger watching someone's social graph fill in, request by request.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('friends.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'friendship.updated';
    }
}
