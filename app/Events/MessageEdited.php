<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Disiarkan saat pesan diedit. Dikirim ke channel yang sama dengan saat
 * pesan pertama dibuat (chat.{recipientId} untuk DM, clan-chat.{clanId}
 * untuk clan) supaya sisi lain memperbarui isi bubble + label "(edited)".
 */
class MessageEdited implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [$this->message->isClanMessage()
            ? new Channel('clan-chat.'.$this->message->clan_id)
            : new Channel('chat.'.$this->message->recipient_id)];
    }

    public function broadcastAs(): string
    {
        return 'message.edited';
    }

    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->message->id,
            'body' => $this->message->body,
            'edited' => true,
            'clanId' => $this->message->clan_id,
        ];
    }
}
