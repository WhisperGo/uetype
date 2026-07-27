<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsChatAccess;
use App\Livewire\Concerns\ManagesChatConversation;
use Livewire\Component;

/**
 * Chat overlay/drawer: mounted once, globally, in layouts.app (outside the
 * Livewire slot, alongside the toast stack) so it survives wire:navigate
 * and is reachable from any page. Same feature set as the full Chat page
 * (reply/edit/delete/clear-chat), but state is plain (no #[Url]) so opening
 * it never rewrites the host page's query string — that's the full /chat
 * page's job, and stays reachable via the "Buka penuh" link.
 *
 * The conversation flow is shared with Chat via ManagesChatConversation.
 */
class ChatOverlay extends Component
{
    use GuardsChatAccess, ManagesChatConversation;

    public const OVERLAY_PAGE_SIZE = 15;

    /** Contact list is capped: narrow drawer, not the full inbox. */
    public const CONTACT_LIMIT = 8;

    public bool $open = false;

    // Active conversation mode: 'dm' | 'clan'. Null = contact picker view.
    // No #[Url] -- see the class doc-comment.
    public ?string $activeMode = null;

    public ?string $withUsername = null;

    /**
     * Unread DM count for the FAB badge. A synced PUBLIC property (not a computed prop) so it
     * can be @entangle'd: the server recomputes it from the DB on every render (source of
     * truth), while the client bumps it locally when a DM arrives with the drawer closed --
     * there is no Livewire round-trip in that case (chat-runtime holds it while inactive), so
     * without a client-side bump the badge would never light up in real time. It reconciles
     * back to the server value on the next render (open drawer / open a DM -> mark read).
     */
    public int $unreadCount = 0;

    protected function pageSize(): int
    {
        return self::OVERLAY_PAGE_SIZE;
    }

    protected function contactLimit(): ?int
    {
        return self::CONTACT_LIMIT;
    }

    // ---- ACTIONS: OPEN/CLOSE ----

    /** Toggle the drawer open/closed. */
    public function toggleOverlay(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Return to the contact list WITHOUT closing the drawer. Semantically different
     * from the full page's closeConversation(), hence its own name.
     */
    public function backToPicker(): void
    {
        $this->resetConversation();
    }

    /**
     * Alias for the overlay view. Named differently from the full page because its
     * contents are capped by CONTACT_LIMIT, not the full inbox.
     */
    public function getRecentContactsProperty()
    {
        return $this->conversations;
    }

    public function render()
    {
        // Refresh the badge from the DB on every render so the entangled property carries the
        // authoritative count to the client (which may have bumped it locally while closed).
        $this->unreadCount = $this->totalUnread;

        return view('livewire.chat-overlay');
    }
}
