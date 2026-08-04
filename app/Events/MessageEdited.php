<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Broadcasts an edited message so the other side updates the bubble and shows the "(edited)" label, on the original channel. */
class MessageEdited implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /** Private for the same reason as the original send: the payload repeats the body. */
    public function broadcastOn(): array
    {
        return [$this->message->isClanMessage()
            ? new PrivateChannel('clan-chat.'.$this->message->clan_id)
            : new PrivateChannel('chat.'.$this->message->recipient_id)];
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
