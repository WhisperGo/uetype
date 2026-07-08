<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Status keanggotaan clan berubah (join/accept/reject/kick/leave); channel per-user 'clan.{userId}'. */
class ClanUpdated implements ShouldBroadcastNow
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
        return [new Channel('clan.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'clan.updated';
    }
}
