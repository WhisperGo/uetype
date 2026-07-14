<?php

namespace App\Livewire;

use App\Enums\FriendshipStatus;
use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Livewire\Concerns\GuardsChatAccess;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageClear;
use App\Models\MessageDelete;
use App\Models\User;
use App\Support\SafeBroadcast;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Chat overlay/drawer: mounted once, globally, in layouts.app (outside the
 * Livewire slot, alongside the toast components) so it survives wire:navigate
 * and is reachable from any page. Same feature set as the full Chat page
 * (reply/edit/delete/clear-chat), but state is plain (no #[Url]) so opening
 * it never rewrites the host page's query string — that's the full /chat
 * page's job, and stays reachable via the "Buka penuh" link.
 */
class ChatOverlay extends Component
{
    use GuardsChatAccess;

    public const OVERLAY_PAGE_SIZE = 15;

    public bool $open = false;

    // Mode percakapan aktif: 'dm' | 'clan'. Null = tampilan picker kontak.
    public ?string $activeMode = null;

    public ?string $withUsername = null;

    public string $body = '';

    public int $loadedOlder = 0;

    public bool $showClearModal = false;

    public string $clearScope = 'all'; // 'all' | 'days'

    public int $clearDays = 7;

    public ?int $editingId = null;

    public string $editBody = '';

    public ?int $replyingToId = null;

    /** Listener Echo untuk pesan baru; body kosong karena action apa pun memicu re-render. */
    #[On('message-received')]
    public function refreshChat(): void
    {
        //
    }

    // ---- AKSI: BUKA/TUTUP ----

    public function toggleOverlay(): void
    {
        $this->open = ! $this->open;
    }

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

    /** Kembali ke picker kontak tanpa menutup drawer. */
    public function backToPicker(): void
    {
        $this->activeMode = null;
        $this->withUsername = null;
        $this->body = '';
        $this->loadedOlder = 0;
    }

    public function loadOlder(): void
    {
        $this->loadedOlder += self::OVERLAY_PAGE_SIZE;
    }

    // ---- AKSI: KIRIM PESAN ----

    /**
     * Kirim pesan. Body diterima sebagai argumen agar input UI langsung dikosongkan
     * tanpa menunggu round-trip. $body opsional, fallback ke $this->body. Jalur UI
     * nyata memakai fetch() (window.chatOverlaySend) untuk latensi; method ini
     * tetap ada sebagai fallback/tercakup test.
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

        if (! $friend) {
            return;
        }

        $this->sendDmMessage($friend->id, $body, $replyToId);
    }

    private function sendClanMessage(string $body, ?int $replyToId = null): void
    {
        $clan = $this->myClan;

        if (! $clan) {
            return;
        }

        $this->sendClanMessageAs($clan->id, $body, $replyToId);
    }

    // ---- AKSI: REPLY ----

    public function startReply(int $messageId): void
    {
        $message = Message::find($messageId);

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

    // ---- DATA (computed) ----

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

    public function getMessagesProperty()
    {
        $take = self::OVERLAY_PAGE_SIZE + $this->loadedOlder;

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

        return $total > (self::OVERLAY_PAGE_SIZE + $this->loadedOlder);
    }

    /**
     * Picker kontak: versi ringkas inbox DM Chat::getConversationsProperty(),
     * dibatasi ke N kontak paling baru diajak bicara (bukan inbox penuh --
     * itu tugas halaman /chat).
     */
    public function getRecentContactsProperty()
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

        $friends = User::query()->whereIn('id', $friendIds)->get();

        // Dua query untuk SELURUH daftar, bukan tiga query per teman (pesan terakhir +
        // lookup MessageClear di dalam visibleTo() + hitungan unread).
        //
        // Overlay ini dirender di layout GLOBAL -- jadi N+1 di sini dibayar oleh SETIAP
        // halaman situs, bukan cuma /chat. Dengan 20 teman itu 60 query tambahan di tiap
        // klik, di halaman mana pun.
        $ids = $friends->pluck('id')->all();
        $lastMessages = Message::lastPerConversation($me, $ids);
        $unreadCounts = Message::unreadCountsFrom($me, $ids);

        return $friends
            ->map(fn (User $friend) => [
                'user' => $friend,
                'lastMessage' => $lastMessages->get($friend->id),
                'unreadCount' => (int) ($unreadCounts->get($friend->id) ?? 0),
                'online' => $friend->isOnline(),
            ])
            ->sortByDesc(fn ($row) => $row['lastMessage']?->created_at ?? Carbon::createFromTimestamp(0))
            ->take(8)
            ->values();
    }

    /** Unread DM global (badge tombol toggle). Clan chat tak punya read-tracking. */
    public function getUnreadCountProperty(): int
    {
        return Message::where('recipient_id', Auth::id())->whereNull('read_at')->count();
    }

    public function render()
    {
        return view('livewire.chat-overlay');
    }
}
