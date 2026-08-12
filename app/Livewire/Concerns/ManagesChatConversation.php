<?php

namespace App\Livewire\Concerns;

use App\Enums\FriendshipStatus;
use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\MessageClear;
use App\Models\MessageDelete;
use App\Models\User;
use App\Support\ChatAccess;
use App\Support\SafeBroadcast;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;

/**
 * The entire chat conversation flow (open, paginate, send, reply, edit, delete, clear)
 * shared by the full-page Chat and the ChatOverlay drawer.
 *
 * Extracted because those two components were once 91% duplicate: 886 lines with only 82
 * differing, and 16 of 19 same-named methods identical byte-for-byte. The consequence was
 * that every chat bug had to be fixed twice -- and sooner or later one side falls behind.
 *
 * Complements GuardsChatAccess (the "who may DM / see whose messages" gate). ChatController
 * deliberately uses neither -- see its doc comment.
 *
 * What does NOT move here and stays in each class:
 *   - $activeMode & $withUsername, because Chat gives them the #[Url] attribute, and
 *     attributes attach to the property declaration so can't live in a trait
 *   - render(), because only Chat sets a layout
 *   - page size & contact limit, via the pageSize()/contactLimit() hooks
 */
trait ManagesChatConversation
{
    public string $body = '';

    // Manual pagination offset for "load more" (scroll-up).
    public int $loadedOlder = 0;

    // "Clear Chat" modal controls (scope choice: all / older than N days).
    public bool $showClearModal = false;

    public string $clearScope = 'all'; // 'all' | 'days'

    public int $clearDays = 7;

    // Inline edit: id of the message being edited (null = none) + its draft.
    public ?int $editingId = null;

    public string $editBody = '';

    // Reply: id of the message being replied to (null = send a normal message).
    public ?int $replyingToId = null;

    // ---- VARIATION POINTS ----

    /** How many messages load per page. The overlay uses fewer because space is tight. */
    abstract protected function pageSize(): int;

    /** Cap on how many contacts are shown; null = unlimited (full inbox). */
    protected function contactLimit(): ?int
    {
        return null;
    }

    /** Echo listener for new messages; empty body because any action triggers a re-render. */
    #[On('message-received')]
    public function refreshChat(): void
    {
        // A new message arrived. If the user is currently looking at that exact DM, mark it
        // read right away -- otherwise the sender spamming while the thread is OPEN would
        // pile up unread rows and light the notification dot for a conversation already on
        // screen. The method itself just triggers a re-render (computed props re-run); the
        // mark-read is the meaningful side effect. Clan chat has no per-message read state,
        // so only DMs need this.
        if ($this->activeMode === 'dm' && $this->activeFriend) {
            $this->markDmAsRead($this->activeFriend->id);
        }
    }

    // ---- ACTION: NAVIGATION ----

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

    /**
     * Close the active conversation without touching other state. On the full page this
     * means returning to the inbox (closeConversation); in the overlay it means returning
     * to the contact list WITHOUT closing the drawer (backToPicker) -- hence the two public
     * names are kept, since the semantics genuinely differ for the user.
     */
    protected function resetConversation(): void
    {
        $this->activeMode = null;
        $this->withUsername = null;
        $this->body = '';
        $this->loadedOlder = 0;
    }

    public function loadOlder(): void
    {
        $this->loadedOlder += $this->pageSize();
    }

    // ---- ACTION: SEND MESSAGE ----

    /**
     * Send a message. Body is passed as an argument so the UI input can be cleared
     * immediately without waiting for a round-trip. $body is optional, falling back to
     * $this->body.
     */
    public function sendMessage(?string $body = null): void
    {
        $body = trim($body ?? $this->body);

        if ($body === '' || mb_strlen($body) > Message::MAX_BODY_LENGTH) {
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

    /**
     * A valid reply_to_id for the active conversation, or null.
     *
     * Delegates to App\Support\ChatAccess so this and ChatController answer the same question
     * with the same code -- the two used to re-implement it side by side.
     */
    private function resolveReplyTargetId(): ?int
    {
        if ($this->activeMode === 'clan') {
            $clan = $this->myClan;

            return $clan ? ChatAccess::clanReplyTarget($this->replyingToId, $clan->id) : null;
        }

        $friend = $this->activeFriend;

        return $friend ? ChatAccess::dmReplyTarget($this->replyingToId, Auth::id(), $friend->id) : null;
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

    // ---- ACTION: REPLY ----

    public function startReply(int $messageId): void
    {
        $message = Message::find($messageId);

        // Can only reply to a visible message that isn't deleted-for-everyone.
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

    // ---- ACTION: EDIT & DELETE MESSAGE ----

    /** Open the inline edit form (sender only, not deleted-for-everyone, within the edit window). */
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

        if ($body === '' || mb_strlen($body) > Message::MAX_BODY_LENGTH) {
            return;
        }

        $message->update([
            'body' => $body,
            'edited_at' => now(),
        ]);

        $this->cancelEdit();

        SafeBroadcast::run(fn () => broadcast(new MessageEdited($message)));
    }

    /** "Delete for everyone": replace the body with a placeholder for all. Sender only. */
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

    /** "Delete for me": hide from this user only, still present for others. */
    public function deleteForMe(int $messageId): void
    {
        $message = Message::find($messageId);

        if (! $message || ! $this->canSeeMessage($message)) {
            return;
        }

        MessageDelete::hide(Auth::id(), $message->id);
    }

    // ---- ACTION: CLEAR CHAT ----

    public function openClearModal(): void
    {
        $this->clearScope = 'all';
        $this->clearDays = 7;
        $this->showClearModal = true;
    }

    public function confirmClear(): void
    {
        // Clamp to the same range as the HTML attributes (min 1, max 3650). max() alone
        // only guards the lower bound; the upper bound from `max="3650"` in the markup
        // means nothing for a hand-crafted request, so it's enforced here.
        $days = min(3650, max(1, $this->clearDays));

        $before = $this->clearScope === 'days'
            ? now()->subDays($days)
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
    // getMyMembershipProperty()/getMyClanProperty() and the security gates
    // (isAcceptedFriend/canSeeMessage/markDmAsRead) live in GuardsChatAccess.

    /** The friend whose conversation is open, null if invalid / not a friend. */
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
     * The active conversation's message history, filtered by visibleTo() (messages this
     * user cleared no longer show but remain in the DB for the other party). Sorted oldest
     * to newest.
     */
    public function getMessagesProperty()
    {
        $take = $this->pageSize() + $this->loadedOlder;

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

    /** The message being replied to (preview above the input), null if invalid. */
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

        return $total > ($this->pageSize() + $this->loadedOlder);
    }

    /**
     * DM inbox: one row per friend messages were exchanged with, sorted by the latest
     * message. Accepted friends only (a broken friendship -> disappears from the inbox,
     * history stays in the DB).
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

        $friends = User::query()->whereIn('id', $friendIds)->get();

        // Last message & unread count for ALL friends at once: two queries, not three per
        // friend (last message + MessageClear lookup inside visibleTo() + unread count).
        // A 30-friend inbox: ~90 queries -> 2.
        $ids = $friends->pluck('id')->all();
        $lastMessages = Message::lastPerConversation($me, $ids);
        $unreadCounts = Message::unreadCountsFrom($me, $ids);

        $rows = $friends
            ->map(fn (User $friend) => [
                'user' => $friend,
                'lastMessage' => $lastMessages->get($friend->id),
                'unreadCount' => (int) ($unreadCounts->get($friend->id) ?? 0),
                'online' => $friend->isOnline(),
            ])
            // Conversations with no messages go to the bottom.
            ->sortByDesc(fn ($row) => $row['lastMessage']?->created_at ?? Carbon::createFromTimestamp(0));

        $limit = $this->contactLimit();

        return ($limit === null ? $rows : $rows->take($limit))->values();
    }

    public function getTotalUnreadProperty(): int
    {
        return Message::where('recipient_id', Auth::id())->whereNull('read_at')->count();
    }
}
