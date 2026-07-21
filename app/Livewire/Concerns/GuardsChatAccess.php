<?php

namespace App\Livewire\Concerns;

use App\Enums\ClanMemberStatus;
use App\Enums\FriendshipStatus;
use App\Events\ClanMessageSent;
use App\Events\DirectMessageSent;
use App\Models\ClanMember;
use App\Models\Message;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\Auth;

/**
 * Shared security gate & message sending for the chat components (the full-page Chat
 * and the ChatOverlay drawer). Extracted so the "who may DM / see whose messages" rules
 * live in one place for both Livewire components — ChatController stays separate (for
 * latency reasons, see its class doc comment) and, not being a Livewire class, doesn't
 * use this trait.
 */
trait GuardsChatAccess
{
    public function getMyMembershipProperty(): ?ClanMember
    {
        return ClanMember::with('clan')
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Active)
            ->first();
    }

    public function getMyClanProperty()
    {
        return $this->myMembership?->clan;
    }

    /** Accepted friends only; re-checked server-side on every action. */
    private function isAcceptedFriend(int $otherId): bool
    {
        $friendship = Auth::user()->friendshipWith($otherId);

        return $friendship?->status === FriendshipStatus::Accepted;
    }

    /** May see (and "delete for me") a message: a DM involving them, or their active clan's. */
    private function canSeeMessage(Message $message): bool
    {
        $me = Auth::id();

        if ($message->isClanMessage()) {
            return $this->myClan && $message->clan_id === $this->myClan->id;
        }

        return $message->sender_id === $me || $message->recipient_id === $me;
    }

    private function markDmAsRead(int $friendId): void
    {
        Message::where('sender_id', $friendId)
            ->where('recipient_id', Auth::id())
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    private function sendDmMessage(int $friendId, string $body, ?int $replyToId = null): void
    {
        if (! $this->isAcceptedFriend($friendId)) {
            return;
        }

        $message = Message::create([
            'sender_id' => Auth::id(),
            'recipient_id' => $friendId,
            'body' => $body,
            'reply_to_id' => $replyToId,
        ]);

        SafeBroadcast::run(fn () => broadcast(new DirectMessageSent($message->load('sender'))));
    }

    private function sendClanMessageAs(int $clanId, string $body, ?int $replyToId = null): void
    {
        $message = Message::create([
            'sender_id' => Auth::id(),
            'clan_id' => $clanId,
            'body' => $body,
            'reply_to_id' => $replyToId,
        ]);

        SafeBroadcast::run(fn () => broadcast(new ClanMessageSent($message->load('sender'))));
    }
}
