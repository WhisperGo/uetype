<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsChatAccess;
use App\Livewire\Concerns\ManagesChatConversation;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The chat page for direct messages and clan channels: active conversation,
 * paginated history, send/edit/delete, driven by the Message* broadcast events.
 *
 * The whole conversation flow lives in ManagesChatConversation, shared with
 * ChatOverlay. What remains here is only what's specific to the full page:
 * URL-persisted state, an unbounded inbox, and the layout.
 */
class Chat extends Component
{
    use GuardsChatAccess, ManagesChatConversation;

    public const PAGE_SIZE = 30;

    // Active conversation mode: 'dm' | 'clan'. Null = inbox view only.
    // #[Url] only here: the full page can be bookmarked & shared, whereas the overlay
    // must not rewrite the URL of the page it's opened over.
    #[Url(as: 'mode')]
    public ?string $activeMode = null;

    // Username of the friend whose conversation is open (mode 'dm').
    #[Url(as: 'with')]
    public ?string $withUsername = null;

    /**
     * Drop a ?mode=clan that the viewer has no clan for, falling back to the inbox.
     *
     * The clan pages link straight here with ?mode=clan, so the parameter now arrives from
     * ordinary navigation rather than only from this page's own buttons -- and it can
     * outlive the membership that justified it: leave or get kicked from a clan, then use
     * the back button or an old bookmark. Without this the page renders the conversation
     * branch with no clan behind it: an empty thread with no header and no way back,
     * because the inbox that holds the "you are not in a clan" card is not drawn either.
     */
    public function mount(): void
    {
        if ($this->activeMode === 'clan' && ! $this->myClan) {
            $this->activeMode = null;
        }
    }

    protected function pageSize(): int
    {
        return self::PAGE_SIZE;
    }

    /** Return to the inbox. */
    public function closeConversation(): void
    {
        $this->resetConversation();
    }

    public function render()
    {
        return view('livewire.chat')->layout('layouts.app');
    }
}
