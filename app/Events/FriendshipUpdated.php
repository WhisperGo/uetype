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

    public $notification;

    /**
     * @param  int  $userId  penerima siaran (channel friends.{userId})
     * @param  array|null  $notification  payload notifikasi opsional:
     *                                    ['type' => 'request'|'accepted', 'message' => string]. Null berarti
     *                                    hanya menyegarkan UI tanpa memunculkan toast.
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
