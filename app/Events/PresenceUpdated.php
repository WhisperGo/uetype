<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disiarkan saat status online/offline seorang user BERUBAH (transisi
 * offline->online, atau saat ia logout). Dikirim ke channel per-user
 * 'friends.{friendId}' milik SETIAP teman, menumpang infrastruktur yang
 * sama dengan FriendshipUpdated — sehingga daftar teman mereka menyegarkan
 * titik status secara real-time tanpa menunggu heartbeat berikutnya.
 *
 * Sengaja TIDAK menyiarkan tiap heartbeat: hanya saat status benar-benar
 * berubah, agar tak membanjiri WebSocket dengan pesan tiap ~30 detik.
 *
 * ShouldBroadcastNow: dikirim langsung tanpa antre queue (tak perlu worker).
 */
class PresenceUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  int  $friendId  penerima siaran (channel friends.{friendId})
     */
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
