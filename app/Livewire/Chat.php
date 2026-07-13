<?php

namespace App\Livewire;

use App\Enums\ClanMemberStatus;
use App\Enums\FriendshipStatus;
use App\Events\ClanMessageSent;
use App\Events\DirectMessageSent;
use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Models\ClanMember;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageClear;
use App\Models\MessageDelete;
use App\Models\User;
use App\Support\SafeBroadcast;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The chat page for direct messages and clan channels: active conversation,
 * paginated history, send/edit/delete, driven by the Message* broadcast events.
 */
class Chat extends Component
{
    public const PAGE_SIZE = 30;

    // Mode percakapan aktif: 'dm' | 'clan'. Null = tampilan inbox saja.
    #[Url(as: 'mode')]
    public ?string $activeMode = null;

    // Username teman yang percakapannya sedang dibuka (mode 'dm').
    #[Url(as: 'with')]
    public ?string $withUsername = null;

    public string $body = '';

    // Offset paginasi manual untuk "load more" (scroll-ke-atas).
    public int $loadedOlder = 0;

    // Kontrol modal "Clear Chat" (pilihan cakupan: semua / lebih lama dari N hari).
    public bool $showClearModal = false;

    public string $clearScope = 'all'; // 'all' | 'days'

    public int $clearDays = 7;

    // Edit inline: id pesan yang sedang diedit (null = tak ada) + draftnya.
    public ?int $editingId = null;

    public string $editBody = '';

    // Reply: id pesan yang sedang dibalas (null = kirim pesan biasa).
    public ?int $replyingToId = null;

    /** Listener Echo untuk pesan baru; body kosong karena action apa pun memicu re-render. */
    #[On('message-received')]
    public function refreshChat(): void
    {
        //
    }

    // ---- AKSI: NAVIGASI ----

    public function openDm(string $username): void
    {
        $friend = User::where('username', $username)->first();

        if (! $friend || ! $this->isAcceptedFriend($friend->id)) {
            return;
        }

        $this->activeMode = 'dm';
        $this->withUsername = $username;
        $this->loadedOlder = 0;
        $this->body = '';

        $this->markDmAsRead($friend->id);
    }

    public function openClanChat(): void
    {
        if (! $this->myClan) {
            return;
        }

        $this->activeMode = 'clan';
        $this->withUsername = null;
        $this->loadedOlder = 0;
        $this->body = '';
    }

    public function closeConversation(): void
    {
        $this->activeMode = null;
        $this->withUsername = null;
        $this->body = '';
        $this->loadedOlder = 0;
    }

    public function loadOlder(): void
    {
        $this->loadedOlder += self::PAGE_SIZE;
    }

    // ---- AKSI: KIRIM PESAN ----

    /**
     * Kirim pesan. Body diterima sebagai argumen agar input UI langsung dikosongkan
     * tanpa menunggu round-trip. $body opsional, fallback ke $this->body.
     */
    public function sendMessage(?string $body = null): void
    {
        $body = trim($body ?? $this->body);

        if ($body === '' || mb_strlen($body) > 2000) {
            return;
        }

        $replyToId = $this->resolveReplyTargetId();

        if ($this->activeMode === 'dm') {
            $this->sendDm($body, $replyToId);
        } elseif ($this->activeMode === 'clan') {
            $this->sendClanMessage($body, $replyToId);
        }

        $this->replyingToId = null;
    }

    /** Reply_to_id yang sah untuk percakapan aktif & boleh dilihat user, atau null. */
    private function resolveReplyTargetId(): ?int
    {
        if (! $this->replyingToId) {
            return null;
        }

        $target = Message::find($this->replyingToId);

        if (! $target || ! $this->canSeeMessage($target)) {
            return null;
        }

        // Pastikan pesan yang dibalas memang milik percakapan yang sama.
        if ($this->activeMode === 'clan') {
            return $target->clan_id === $this->myClan?->id ? $target->id : null;
        }

        $friend = $this->activeFriend;

        return $friend && ! $target->isClanMessage()
            && in_array($friend->id, [$target->sender_id, $target->recipient_id], true)
            && in_array(Auth::id(), [$target->sender_id, $target->recipient_id], true)
            ? $target->id
            : null;
    }

    private function sendDm(string $body, ?int $replyToId = null): void
    {
        $friend = $this->activeFriend;

        if (! $friend || ! $this->isAcceptedFriend($friend->id)) {
            return;
        }

        $message = Message::create([
            'sender_id' => Auth::id(),
            'recipient_id' => $friend->id,
            'body' => $body,
            'reply_to_id' => $replyToId,
        ]);

        SafeBroadcast::run(fn () => broadcast(new DirectMessageSent($message->load('sender'))));
    }

    private function sendClanMessage(string $body, ?int $replyToId = null): void
    {
        $clan = $this->myClan;

        if (! $clan) {
            return;
        }

        $message = Message::create([
            'sender_id' => Auth::id(),
            'clan_id' => $clan->id,
            'body' => $body,
            'reply_to_id' => $replyToId,
        ]);

        SafeBroadcast::run(fn () => broadcast(new ClanMessageSent($message->load('sender'))));
    }

    // ---- AKSI: REPLY ----

    public function startReply(int $messageId): void
    {
        $message = Message::find($messageId);

        // Hanya boleh reply pesan yang boleh dilihat & belum dihapus-untuk-semua.
        if (! $message || ! $this->canSeeMessage($message) || $message->isDeletedForEveryone()) {
            return;
        }

        $this->replyingToId = $message->id;
        $this->editingId = null;
    }

    public function cancelReply(): void
    {
        $this->replyingToId = null;
    }

    // ---- AKSI: EDIT & DELETE PESAN ----

    /** Buka form edit inline (hanya pengirim, belum dihapus-untuk-semua, dalam jendela edit). */
    public function startEdit(int $messageId): void
    {
        $message = Message::find($messageId);

        if (! $message || ! $message->canBeEditedBy(Auth::id())) {
            return;
        }

        $this->editingId = $message->id;
        $this->editBody = $message->body;
    }

    public function cancelEdit(): void
    {
        $this->editingId = null;
        $this->editBody = '';
    }

    public function saveEdit(): void
    {
        $message = Message::find($this->editingId);

        if (! $message || ! $message->canBeEditedBy(Auth::id())) {
            $this->cancelEdit();

            return;
        }

        $body = trim($this->editBody);

        if ($body === '' || mb_strlen($body) > 2000) {
            return;
        }

        $message->update([
            'body' => $body,
            'edited_at' => now(),
        ]);

        $this->cancelEdit();

        SafeBroadcast::run(fn () => broadcast(new MessageEdited($message)));
    }

    /** "Delete for everyone": ganti isi jadi placeholder untuk semua. Hanya pengirim. */
    public function deleteForEveryone(int $messageId): void
    {
        $message = Message::find($messageId);

        if (! $message || ! $message->canBeDeletedForEveryoneBy(Auth::id())) {
            return;
        }

        $message->update([
            'deleted_for_everyone_at' => now(),
        ]);

        SafeBroadcast::run(fn () => broadcast(new MessageDeleted($message)));
    }

    /** "Delete for me": sembunyikan hanya dari user ini, tetap ada untuk orang lain. */
    public function deleteForMe(int $messageId): void
    {
        $message = Message::find($messageId);

        if (! $message || ! $this->canSeeMessage($message)) {
            return;
        }

        MessageDelete::hide(Auth::id(), $message->id);
    }

    // ---- AKSI: CLEAR CHAT ----

    public function openClearModal(): void
    {
        $this->clearScope = 'all';
        $this->clearDays = 7;
        $this->showClearModal = true;
    }

    public function confirmClear(): void
    {
        $before = $this->clearScope === 'days'
            ? now()->subDays(max(1, $this->clearDays))
            : now();

        if ($this->activeMode === 'dm' && $this->activeFriend) {
            MessageClear::clearDm(Auth::id(), $this->activeFriend->id, $before);
        } elseif ($this->activeMode === 'clan' && $this->myClan) {
            MessageClear::clearClan(Auth::id(), $this->myClan->id, $before);
        }

        $this->showClearModal = false;
        $this->loadedOlder = 0;
    }

    // ---- GERBANG KEAMANAN ----

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

    // ---- DATA (computed) ----

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

    /** Teman yang sedang dibuka percakapannya, null kalau tak valid/bukan teman. */
    public function getActiveFriendProperty(): ?User
    {
        if ($this->activeMode !== 'dm' || ! $this->withUsername) {
            return null;
        }

        $friend = User::where('username', $this->withUsername)->first();

        if (! $friend || ! $this->isAcceptedFriend($friend->id)) {
            return null;
        }

        return $friend;
    }

    /**
     * Riwayat pesan percakapan aktif, disaring visibleTo() (pesan yang di-clear user
     * ini tak muncul lagi tapi tetap ada di DB untuk lawan bicara). Diurutkan lama->baru.
     */
    public function getMessagesProperty()
    {
        $take = self::PAGE_SIZE + $this->loadedOlder;

        if ($this->activeMode === 'dm') {
            $friend = $this->activeFriend;
            if (! $friend) {
                return collect();
            }

            return Message::between(Auth::id(), $friend->id)
                ->visibleTo(Auth::id(), otherUserId: $friend->id)
                ->with('replyTo.sender')
                ->latest('id')
                ->take($take)
                ->get()
                ->sortBy('id')
                ->values();
        }

        if ($this->activeMode === 'clan') {
            $clan = $this->myClan;
            if (! $clan) {
                return collect();
            }

            return Message::inClan($clan->id)
                ->visibleTo(Auth::id(), clanId: $clan->id)
                ->with(['sender', 'replyTo.sender'])
                ->latest('id')
                ->take($take)
                ->get()
                ->sortBy('id')
                ->values();
        }

        return collect();
    }

    /** Pesan yang sedang dibalas (preview di atas input), null kalau tak valid. */
    public function getReplyingToProperty(): ?Message
    {
        if (! $this->replyingToId) {
            return null;
        }

        $message = Message::with('sender')->find($this->replyingToId);

        return $message && $this->canSeeMessage($message) ? $message : null;
    }

    public function getHasMoreOlderProperty(): bool
    {
        $total = 0;

        if ($this->activeMode === 'dm' && $this->activeFriend) {
            $total = Message::between(Auth::id(), $this->activeFriend->id)
                ->visibleTo(Auth::id(), otherUserId: $this->activeFriend->id)
                ->count();
        } elseif ($this->activeMode === 'clan' && $this->myClan) {
            $total = Message::inClan($this->myClan->id)
                ->visibleTo(Auth::id(), clanId: $this->myClan->id)
                ->count();
        }

        return $total > (self::PAGE_SIZE + $this->loadedOlder);
    }

    /**
     * Inbox DM: satu baris per teman yang pernah ditukar pesan, diurutkan pesan
     * terakhir. Hanya teman berstatus accepted (pertemanan putus -> hilang dari
     * inbox, riwayat tetap ada di DB).
     */
    public function getConversationsProperty()
    {
        $me = Auth::id();

        $friendIds = Friendship::query()
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $me)->orWhere('addressee_id', $me))
            ->get()
            ->map(fn (Friendship $f) => $f->requester_id === $me ? $f->addressee_id : $f->requester_id);

        if ($friendIds->isEmpty()) {
            return collect();
        }

        return User::query()
            ->whereIn('id', $friendIds)
            ->get()
            ->map(function (User $friend) use ($me) {
                $lastMessage = Message::between($me, $friend->id)
                    ->visibleTo($me, otherUserId: $friend->id)
                    ->latest('id')
                    ->first();

                $unreadCount = Message::where('sender_id', $friend->id)
                    ->where('recipient_id', $me)
                    ->whereNull('read_at')
                    ->count();

                return [
                    'user' => $friend,
                    'lastMessage' => $lastMessage,
                    'unreadCount' => $unreadCount,
                    'online' => $friend->isOnline(),
                ];
            })
            // Percakapan tanpa pesan ditaruh di bawah.
            ->sortByDesc(fn ($row) => $row['lastMessage']?->created_at ?? Carbon::createFromTimestamp(0))
            ->values();
    }

    public function getTotalUnreadProperty(): int
    {
        return Message::where('recipient_id', Auth::id())->whereNull('read_at')->count();
    }

    public function render()
    {
        return view('livewire.chat')->layout('layouts.app');
    }
}
