<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Status pertemanan berubah (request/accept/reject/cancel/remove); channel per-user 'friends.{userId}'. */
class FriendshipUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;

    public $notification;

    /**
     * @param  array|null  $notification  ['type' => 'request'|'accepted', 'message' => string]; null = refresh UI tanpa toast.
     */
    public function __construct($userId, ?array $notification = null)
    {
        $this->userId = $userId;
        $this->notification = $notification;
    }

    public function broadcastOn(): array
    {
        return [new Channel('friends.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'friendship.updated';
    }
}
