<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disiarkan saat status pertemanan seorang user berubah (ada request masuk,
 * request diterima/ditolak/dibatalkan, atau teman dihapus). Dikirim ke channel
 * per-user 'friends.{userId}' sehingga UI Friends milik user tersebut bisa
 * memuat ulang datanya secara real-time tanpa polling.
 *
 * ShouldBroadcastNow: dikirim langsung tanpa antre queue (tak perlu worker).
 */
class FriendshipUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;

    public function __construct($userId)
    {
        $this->userId = $userId;
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
