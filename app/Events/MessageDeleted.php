<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** "Delete for everyone": sisi lain ganti bubble jadi placeholder; channel sama seperti saat dibuat. */
class MessageDeleted implements ShouldBroadcastNow
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
        return 'message.deleted';
    }

    public function broadcastWith(): array
    {
        return [
            'messageId' => $this->message->id,
            'clanId' => $this->message->clan_id,
        ];
    }
}
