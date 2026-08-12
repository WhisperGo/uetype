<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Broadcasts a new clan chat message on the per-clan channel 'clan-chat.{clanId}', so every member receives it at once. */
class ClanMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * PRIVATE: clan ids are sequential too, so this was every clan's chat readable by anyone.
     * Authorization (active membership) lives in routes/channels.php, matching the same rule
     * GuardsChatAccess enforces on the send path.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('clan-chat.'.$this->message->clan_id)];
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
