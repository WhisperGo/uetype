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
 * Gerbang keamanan & pengiriman pesan bersama untuk komponen chat (halaman
 * penuh Chat dan overlay ChatOverlay). Diekstrak supaya aturan "siapa boleh
 * DM/lihat pesan siapa" hanya hidup di satu tempat untuk kedua komponen
 * Livewire — ChatController tetap terpisah (alasan latency, lihat class
 * doc-comment-nya), bukan kelas Livewire jadi tak ikut memakai trait ini.
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

    /** Hanya teman berstatus accepted; dicek ulang server-side tiap aksi. */
    private function isAcceptedFriend(int $otherId): bool
    {
        $friendship = Auth::user()->friendshipWith($otherId);

        return $friendship?->status === FriendshipStatus::Accepted;
    }

    /** Berhak melihat (dan "delete for me") pesan: DM yang melibatkan dirinya, atau clan aktifnya. */
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
