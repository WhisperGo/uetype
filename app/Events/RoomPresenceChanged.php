<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notifikasi kehadiran di room multiplayer: seseorang masuk ('join') atau keluar
 * ('leave'). Ditampilkan sebagai pesan sistem di tengah panel chat, bukan bubble
 * biasa. Broadcast-only (tak disimpan), numpang channel 'room.{code}' yang sudah
 * di-subscribe — frontend cukup satu listener tambahan ('.room.presence').
 */
class RoomPresenceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /** @param  'join'|'leave'  $action */
    public function __construct(
        public string $roomCode,
        public string $username,
        public string $action,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('room.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'room.presence';
    }

    public function broadcastWith(): array
    {
        return [
            'username' => $this->username,
            'action' => $this->action,
        ];
    }
}
