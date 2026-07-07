<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disiarkan saat pesan DM (chat teman) terkirim. Dikirim ke channel per-user
 * 'chat.{recipientId}' milik PENERIMA saja (pengirim sudah tahu pesannya
 * sendiri lewat optimistic update Livewire) -- pola sama dgn FriendshipUpdated/
 * ClanUpdated/PresenceUpdated (channel publik per-user, ShouldBroadcastNow).
 */
class DirectMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [new Channel('chat.'.$this->message->recipient_id)];
    }

    public function broadcastAs(): string
    {
        return 'dm.sent';
    }

    /**
     * Payload minimal -- cukup untuk toast + menyegarkan jendela chat kalau
     * sedang terbuka. Tak menyertakan seluruh model (hindari bocor kolom
     * yang tak perlu ke WebSocket publik).
     */
    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->message->id,
            'senderId' => $this->message->sender_id,
            'senderUsername' => $this->message->sender->username,
            'body' => $this->message->body,
        ];
    }
}
