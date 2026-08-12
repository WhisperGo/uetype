<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** "Delete for everyone": tells the other side to replace the bubble with a placeholder, on the same channel it was sent. */
class MessageDeleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * Private to match the channel it was sent on. It carries no body, but a public retraction
     * feed still tells an eavesdropper which conversations are live and how busy they are.
     */
    public function broadcastOn(): array
    {
        return [$this->message->isClanMessage()
            ? new PrivateChannel('clan-chat.'.$this->message->clan_id)
            : new PrivateChannel('chat.'.$this->message->recipient_id)];
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
