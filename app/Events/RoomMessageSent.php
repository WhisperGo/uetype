<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Pesan obrolan di dalam room multiplayer. Sengaja broadcast-only (tidak disimpan
 * ke database): obrolan lobby bersifat sesaat dan ikut hilang saat room bubar, jadi
 * tak perlu tabel/migrasi. Numpang channel 'room.{code}' yang SUDAH di-subscribe
 * race-echo.js, jadi tak ada channel baru — frontend cukup menambah satu listener.
 */
class RoomMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public string $roomCode,
        public int $senderId,
        public string $senderUsername,
        public string $body,
    ) {}

    public function broadcastOn(): array
    {
        return [new Channel('room.'.$this->roomCode)];
    }

    public function broadcastAs(): string
    {
        return 'room.message';
    }

    public function broadcastWith(): array
    {
        return [
            'senderId' => $this->senderId,
            'senderUsername' => $this->senderUsername,
            'body' => $this->body,
            'at' => now()->toIso8601String(),
        ];
    }
}
