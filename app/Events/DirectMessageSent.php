<?php

namespace App\Events;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/** Delivers a direct message to the recipient only, on 'chat.{recipientId}' (the sender already sees it via optimistic update). */
class DirectMessageSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Message $message) {}

    /**
     * PRIVATE, and this one matters most of all: the payload below carries the message body.
     *
     * This was a plain Channel keyed by a sequential user id, so `Echo.channel('chat.5')` read
     * every DM arriving for user #5 in real time -- no guessing needed, and the app key that
     * makes it possible has to ship in the bundle. The 'encrypted' cast on Message::$body does
     * not help here: it decrypts on attribute access, so what went over the wire was plaintext.
     *
     * Authorized in routes/channels.php.
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('chat.'.$this->message->recipient_id)];
    }

    public function broadcastAs(): string
    {
        return 'dm.sent';
    }

    /** Minimal payload (not the whole model): the channel is private, the row still isn't public. */
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
