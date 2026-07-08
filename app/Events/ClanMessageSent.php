<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Pesan chat clan; channel per-clan 'clan-chat.{clanId}' (bukan per-user, semua anggota dengar bersama). */
class ClanMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    public function broadcastOn(): array
    {
        return [new Channel('clan-chat.'.$this->message->clan_id)];
    }

    public function broadcastAs(): string
    {
        return 'clan-message.sent';
    }

    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->message->id,
            'senderId' => $this->message->sender_id,
            'senderUsername' => $this->message->sender->username,
            'body' => $this->message->body,
            'clanId' => $this->message->clan_id,
        ];
    }
}
