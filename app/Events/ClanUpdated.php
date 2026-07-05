<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disiarkan saat status keanggotaan clan seorang user berubah (ada
 * permintaan gabung masuk, permintaan diterima/ditolak, dikeluarkan, atau
 * keluar). Dikirim ke channel per-user 'clan.{userId}' sehingga UI Clans
 * milik user tersebut bisa memuat ulang datanya secara real-time.
 *
 * ShouldBroadcastNow: dikirim langsung tanpa antre queue (tak perlu worker).
 */
class ClanUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public $userId;

    public $notification;

    /**
     * @param  int  $userId  penerima siaran (channel clan.{userId})
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
        return [new Channel('clan.'.$this->userId)];
    }

    public function broadcastAs(): string
    {
        return 'clan.updated';
    }
}
