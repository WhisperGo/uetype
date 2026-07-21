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
