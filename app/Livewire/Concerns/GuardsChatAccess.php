<?php

namespace App\Livewire\Concerns;

use App\Enums\ClanMemberStatus;
use App\Events\ClanMessageSent;
use App\Events\DirectMessageSent;
use App\Models\ClanMember;
use App\Models\Message;
use App\Support\ChatAccess;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\Auth;

/**
 * Message sending for the chat components (the full-page Chat and the ChatOverlay drawer),
 * plus the Livewire-shaped wrappers around the access rules.
 *
 * The rules THEMSELVES live in App\Support\ChatAccess, not here: ChatController needs the
 * same answers and cannot use a Livewire trait, so keeping them in a trait is exactly what
 * made them get written twice. This trait now only adapts them to what a component has on
 * hand (Auth::id(), $this->myClan).
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
        return ChatAccess::isAcceptedFriend(Auth::id(), $otherId);
    }

    /** May see (and "delete for me") a message: a DM involving them, or their active clan's. */
    private function canSeeMessage(Message $message): bool
    {
        return ChatAccess::canSeeMessage($message, Auth::id(), $this->myClan?->id);
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

    /**
     * Post into a clan's chat. The membership check lives HERE, not in the caller.
     *
     * It used to rely entirely on its one caller passing $this->myClan->id, which was correct
     * but left this trait asymmetric: sendDmMessage() above re-checks the friendship itself.
     * A method named sendClanMessageAs() sitting in GuardsChatAccess is exactly what the next
     * caller will assume is already guarded -- and the cost of removing that whole class of
     * mistake is one lookup.
     */
    private function sendClanMessageAs(int $clanId, string $body, ?int $replyToId = null): void
    {
        // Active membership only: a pending join request is not membership, and ChatAccess
        // already answers this question for the controller path.
        if (ChatAccess::activeClan(Auth::id())?->id !== $clanId) {
            return;
        }

        $message = Message::create([
            'sender_id' => Auth::id(),
            'clan_id' => $clanId,
            'body' => $body,
            'reply_to_id' => $replyToId,
        ]);

        SafeBroadcast::run(fn () => broadcast(new ClanMessageSent($message->load('sender'))));
    }
}
