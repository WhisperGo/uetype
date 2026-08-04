<?php

namespace App\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Notifies a user of a clan membership change (join/accept/reject/kick/leave) on their channel 'clan.{userId}'. */
class ClanUpdated implements ShouldBroadcastNow
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

    /** Private: keyed by a sequential user id, like every other per-user channel here. */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clan.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'clan.updated';
    }
}
