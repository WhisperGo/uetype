<?php

namespace App\Livewire;

use App\Livewire\Concerns\GuardsChatAccess;
use App\Livewire\Concerns\ManagesChatConversation;
use App\Support\BackLink;
use Illuminate\Http\Request;
use Livewire\Attributes\Locked;
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
     * Where the conversation header's back control points, or null when the conversation was
     * opened from the inbox on this same page.
     *
     * A conversation opened by LINK (the clan hub, clan detail, the overlay's "open full")
     * was never preceded by the inbox, so closing it dropped the visitor on a page they had
     * never been on -- landing at the chat list after arriving from a clan was the reported
     * bug. One opened from the inbox must still return to it, which is a state change rather
     * than a navigation, so the two cases render different controls.
     *
     * #[Locked]: this value is written straight into an href, so a client that could choose
     * it could turn this page into a redirect to anywhere.
     */
    #[Locked]
    public ?string $backUrl = null;

    /** Origins to refuse: /chat back to /chat is the loop the inbox fallback already covers. */
    private const BACK_EXCEPT = ['/chat'];

    /**
     * Drop a ?mode=clan the viewer has no clan for, falling back to the inbox, then remember
     * where a linked-to conversation was opened from.
     *
     * The clan pages link straight here, so the parameter can outlive the membership that
     * justified it (leave a clan, then hit back or an old bookmark). Without this the page
     * renders the conversation branch with no clan behind it: an empty thread with no
     * header, and no inbox to show the "you are not in a clan" card either.
     *
     * The Referer is only real on THIS request: every later Livewire round-trip carries the
     * chat page itself as its referer, so the origin has to be captured once, here.
     */
    public function mount(Request $request): void
    {
        if ($this->activeMode === 'clan' && ! $this->myClan) {
            $this->activeMode = null;
        }

        // After the clan guard above, so a dropped ?mode=clan gets no back link to a
        // conversation that is not being rendered.
        if ($this->activeMode !== null) {
            $this->backUrl = BackLink::from($request, route('chat.index'), self::BACK_EXCEPT);
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
