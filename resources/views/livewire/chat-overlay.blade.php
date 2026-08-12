{{-- Floating chat overlay: a toggle bubble (FAB) plus a docked drawer with a conversation
     picker and thread view. Mirrors the full chat page in a compact form. Its Echo
     subscription is owned by the global toast in the layout; this overlay only listens to
     relayed window events.

     The FAB OWNS the bottom-right corner and is pinned there. Nothing that appears
     unbidden may anchor to this corner -- notifications live in `.notif-lane` on the
     opposite side (see resources/css/app.css). It used to be draggable; see the header of
     resources/js/chat-dock.js for why that was removed. --}}
<div x-data="chatOverlayDock(@entangle('open'), @entangle('unreadCount'))"
    {{-- Live unread badge. toasts.js fires `chat-unread-bump` when a DM arrives that isn't the
         conversation currently on screen (same condition as the toast). While the drawer is
         CLOSED there is no Livewire round-trip, so we bump the count client-side to light the
         badge in real time; when the drawer is OPEN the overlay is active and the server
         re-renders and syncs `unreadCount` itself, so we must NOT double-count here. --}}
    @chat-unread-bump.window="if (! open) unread++"
    class="font-mono">
    {{-- Toggle button: hidden while a test/race session is active.
         @entangle('open') syncs the value to the server (triggering a fresh content render).

         Plain @click on a native <button>, so Enter/Space work with no keyboard handler of
         our own. This is not a detail to "optimise" back into a pointer event: doing so is
         exactly what made the chat unreachable by keyboard before. --}}
    {{-- Nested x-data adds a `navMenuOpen` flag WITHOUT touching chat-dock.js: while the
         mobile nav overlay is open, this FAB (pinned bottom-right, z-[56]) would sit on top
         of the menu, so it hides itself. `hidden` still resolves from the parent
         chatOverlayDock scope -- Alpine inherits parent scope into child x-data. The nav
         broadcasts these window events from nav-badges.js. --}}
    <button x-data="{ navMenuOpen: false }"
        @mobile-nav-opened.window="navMenuOpen = true"
        @mobile-nav-closed.window="navMenuOpen = false"
        x-show="!hidden && !navMenuOpen" x-cloak
        @click="open = ! open"
        :aria-expanded="open ? 'true' : 'false'"
        aria-controls="chat-overlay-panel"
        {{-- Deliberately NOT using <x-btn-gold>: this is a round icon FAB, not a text
             button. Forcing it into that component would only add props no one uses.

             hover:-translate-y-0.5 + active:scale-95 is where the "more feel" that used to
             justify dragging now lives -- on the button's REACTION rather than its
             position. The press feedback also replaces the tactile cue lost with
             `active:cursor-grabbing`. `transition` (not transition-colors) so transform and
             shadow ease too; the reduced-motion block in app.css flattens all of it. --}}
        {{-- A TRUE corner FAB: bottom-5 mirrors right-5, so it reads as pinned to the
             bottom-right corner rather than floating awkwardly above it. It is deliberately
             NOT lifted to clear the footer -- lifting the button (it used to sit at bottom-16)
             was the wrong tool: it looked detached from the corner on desktop yet STILL
             overlapped the taller, centred mobile footer, because one fixed offset can't clear
             both. The footer instead reserves its own bottom safe-zone (see
             layouts/app.blade.php), so the corner stays clean on every page while the footer
             links are never covered. --}}
        class="fixed z-[56] bottom-5 right-5 w-14 h-14 rounded-full bg-gold hover:bg-gold/90 text-background shadow-xl hover:shadow-2xl flex items-center justify-center transition duration-200 hover:-translate-y-0.5 active:scale-95 active:translate-y-0 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-gold focus-visible:ring-offset-2 focus-visible:ring-offset-background"
        aria-label="{{ __('chat.title') }}">
        {{-- Rotates slightly while the drawer is open: a quiet "this is the thing that is
             currently showing", not a second icon to maintain. --}}
        <svg class="w-6 h-6 pointer-events-none transition-transform duration-200" :class="open ? 'rotate-12' : ''"
            fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.17 0-2.29-.2-3.32-.56L3 21l1.56-4.68C3.57 15.19 3 13.65 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
        {{-- Unread badge, driven by the Alpine `unread` state (entangled with the server's
             unreadCount). x-effect replays the chat-badge-pop animation whenever the count
             changes: it removes the class, forces a reflow ($el.offsetWidth), then re-adds it,
             so the keyframes restart even though the node itself is never replaced. The count
             already excludes your own messages and the thread you're looking at -- toasts.js
             only bumps it for a DM that isn't the one on screen, and the server recomputes it
             from the same read-state query. --}}
        <span x-show="unread > 0" x-cloak
            x-text="unread > 9 ? '9+' : unread"
            x-effect="if (unread > 0) { $el.classList.remove('chat-badge-pop'); $el.offsetWidth; $el.classList.add('chat-badge-pop'); }"
            class="absolute -top-1 -right-1 px-1.5 py-0.5 min-w-[1.25rem] text-center font-mono text-[0.65rem] font-bold text-white bg-danger rounded-full border-2 border-background pointer-events-none"></span>
    </button>

    {{-- Drawer: opens upward from the pinned FAB. bottom-24 (6rem) leaves a ~20px gap above
         the button now that it sits at bottom-5 -- 20px offset + 56px button + 20px gap.
         max-h-[70vh] keeps it whole on short viewports, so no JS measuring is needed. --}}
    <div x-show="open && !hidden" x-cloak id="chat-overlay-panel"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-4"
        class="fixed z-[55] bottom-24 right-5 w-96 max-w-[calc(100vw-2.5rem)] h-[32rem] max-h-[70vh] bg-surface border border-white/10 rounded-2xl shadow-2xl flex flex-col overflow-hidden">

        @if ($activeMode === null)
            {{-- PICKER --}}
            {{-- Padding stays `p-4` -- the header height comes from the prototype and is not
                 ours to change. The 44px icon buttons would grow this row to ~76px, so each
                 one carries `-my-3` to pull its click box back out of the flow: the box still
                 measures 44px for the finger, the row still measures what it always did. --}}
            <div class="flex items-center justify-between p-4 border-b border-white/5 shrink-0">
                <p class="font-display text-sm text-foreground">{{ __('chat.title') }}</p>
                <div class="flex items-center gap-1">
                    <x-icon-button as="a" href="{{ route('chat.index') }}" wire:navigate class="-my-3" :label="__('chat.open_full')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                        </svg>
                    </x-icon-button>
                    <x-icon-button @click="open = false" class="-my-3 -me-1" :label="__('chat.close')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </x-icon-button>
                </div>
            </div>

            <div class="flex-1 overflow-y-auto chat-scroll p-3 space-y-2">
                @if ($this->recentContacts->count() > 0)
                    @foreach ($this->recentContacts as $row)
                        <button wire:click="openDm('{{ $row['user']->username }}')"
                            class="w-full flex items-center gap-3 p-2.5 border bg-surface/40 border-white/5 rounded-xl hover:border-white/10 transition text-left group"
                            wire:key="overlay-conv-{{ $row['user']->id }}">
                            <x-friend-avatar :user="$row['user']" :online="$row['online']" size="w-8 h-8" fallback-size="w-5 h-5" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-xs font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $row['user']->username }}</p>
                                <p class="font-mono text-[0.7rem] text-muted mt-0.5 truncate">
                                    @if ($row['lastMessage'])
                                        {{ Str::limit($row['lastMessage']->body, 40) }}
                                    @else
                                        {{ __('chat.no_messages_yet') }}
                                    @endif
                                </p>
                            </div>
                            @if ($row['unreadCount'] > 0)
                                <span class="px-1.5 py-0.5 min-w-[1.25rem] text-center font-mono text-[0.65rem] font-bold text-background bg-gold rounded-full shrink-0">
                                    {{ $row['unreadCount'] }}
                                </span>
                            @endif
                        </button>
                    @endforeach
                @else
                    <div class="flex flex-col items-center justify-center py-10 text-center select-none">
                        <p class="font-mono text-xs font-bold text-foreground">{{ __('chat.empty_inbox_title') }}</p>
                        <a href="{{ route('friends.index') }}" wire:navigate class="mt-3 font-mono text-[0.7rem] text-brand-bright hover:underline">
                            {{ __('chat.go_to_friends') }}
                        </a>
                    </div>
                @endif

                @if ($this->myClan)
                    <button wire:click="openClanChat"
                        class="w-full flex items-center gap-3 p-2.5 border bg-surface/40 border-white/5 rounded-xl hover:border-white/10 transition text-left group">
                        <x-clan-emblem :clan="$this->myClan" size="sm" />
                        <div class="flex-1 min-w-0">
                            <p class="font-mono text-xs font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $this->myClan->name }}</p>
                            <p class="font-mono text-[0.7rem] text-muted mt-0.5">{{ __('chat.tab_clan') }}</p>
                        </div>
                    </button>
                @endif
            </div>
        @else
            {{-- THREAD --}}
            {{-- Padding stays `p-3`, same reasoning as the picker header above: the row height
                 is the prototype's, and each 44px button pulls its own click box back out of
                 the flow with `-my-2.5`. --}}
            <div class="flex items-center gap-1 p-3 border-b border-white/5 shrink-0">
                <x-icon-button wire:click="backToPicker" class="-my-2.5 -ms-1" :label="__('chat.back')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </x-icon-button>

                @if ($activeMode === 'dm' && $this->activeFriend)
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        <x-friend-avatar :user="$this->activeFriend" :online="$this->activeFriend->isOnline()" size="w-7 h-7" fallback-size="w-4 h-4" />
                        <p class="font-mono text-xs font-bold text-foreground truncate">{{ $this->activeFriend->username }}</p>
                    </div>
                    <x-icon-button as="a" href="{{ route('chat.index', ['mode' => 'dm', 'with' => $this->activeFriend->username]) }}" wire:navigate class="-my-2.5" :label="__('chat.open_full')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                        </svg>
                    </x-icon-button>
                @elseif ($activeMode === 'clan' && $this->myClan)
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        <x-clan-emblem :clan="$this->myClan" size="sm" />
                        <p class="font-mono text-xs font-bold text-foreground truncate">{{ $this->myClan->name }}</p>
                    </div>
                    <x-icon-button as="a" href="{{ route('chat.index', ['mode' => 'clan']) }}" wire:navigate class="-my-2.5" :label="__('chat.open_full')">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                        </svg>
                    </x-icon-button>
                @endif

                {{-- Clear-chat is DESTRUCTIVE and sits next to close, so size alone is not
                     enough: at 24px with a `gap-2` between them, a thumb aiming for "close"
                     could wipe the history instead. `tone="danger"` makes it read differently
                     the moment it is touched, and the extra `ms-1` buys separation. Size
                     fixes "can't press it"; distance and colour fix "pressed the wrong one". --}}
                <x-icon-button wire:click="openClearModal" tone="danger" class="-my-2.5 ms-1" :label="__('chat.clear_chat')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                    </svg>
                </x-icon-button>
                <x-icon-button @click="open = false" class="-my-2.5 -me-1" :label="__('chat.close')">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </x-icon-button>
            </div>

            {{-- Message list --}}
            <div x-data="chatOverlayScroll()" x-init="init()" id="overlay-chat-messages"
                class="flex-1 overflow-y-auto chat-scroll p-3 space-y-2.5">
                @if ($this->hasMoreOlder)
                    <div class="flex justify-center pb-2">
                        {{-- Raw size: the overlay uses 0.65rem text, outside the btn-ghost size map. --}}
                        <x-btn-ghost size="px-2.5 py-1 text-[0.65rem]" wire:click="loadOlder">{{ __('chat.load_older') }}</x-btn-ghost>
                    </div>
                @endif

                @forelse ($this->messages as $message)
                    <x-chat.message :message="$message" :active-mode="$activeMode" :editing-id="$editingId"
                        size="sm" keyPrefix="overlay-msg" containerId="overlay-chat-messages"
                        scrollEvent="chat-overlay-scrolled" scrollFn="window.chatOverlayScrollToMessage" />
                @empty
                    <p class="font-mono text-xs text-muted text-center py-8">{{ __('chat.no_messages_yet') }}</p>
                @endforelse
            </div>

            {{-- Reply preview --}}
            @if ($this->replyingTo)
                <x-chat.reply-preview :message="$this->replyingTo" size="sm" />
            @endif

            <x-chat.composer size="sm" sendFn="window.chatOverlaySend" inputRef="overlayMsgInput" />
        @endif
    </div>

    {{-- MODAL: CLEAR CHAT --}}
    @if ($showClearModal)
        {{-- z above the overlay drawer (z-[55]) so the modal isn't buried. --}}
        <x-chat.clear-modal z="z-[57]" />
    @endif

    {{-- REAL-TIME: the Echo subscription is owned by chatToasts() in the layout (the
         only subscriber). This overlay just listens to the window events it relays.

         The runtime body lives in resources/js/chat-runtime.js. What did NOT move there:
         window.__chatOverlayState. chatToasts() reads that flag to decide when to
         suppress a toast, and ONLY the overlay may write it -- if the full page wrote
         it too, toasts would be suppressed on the wrong page. So it stays here,
         visible, rather than hidden in the shared module. --}}
    @script
        <script>
            // Initialize from the current $wire values (not assumed defaults) so
            // chatToasts() is accurate the moment this script runs, without waiting for
            // the first watcher. $watch only fires on change, not on init.
            window.__chatOverlayState = {
                open: $wire.open,
                mode: $wire.activeMode,
                withUsername: $wire.withUsername,
            };

            // Announce open/closed state and the active thread whenever a Livewire property
            // changes, so chatToasts() in the layout knows when to suppress a toast for this thread.
            $wire.$watch('open', (v) => { window.__chatOverlayState.open = v; });
            $wire.$watch('activeMode', (v) => { window.__chatOverlayState.mode = v; });
            $wire.$watch('withUsername', (v) => { window.__chatOverlayState.withUsername = v; });

            window.createChatRuntime({
                variant: 'overlay',
                wire: $wire,
                meId: {{ auth()->id() }},
                sendUrl: @js(route('chat.send')),
                labels: {
                    edited: @js(__('chat.edited')),
                    deleted: @js(__('chat.deleted_placeholder')),
                },
                // Closed drawer = not visible: don't draw bubbles into it and don't
                // trigger a Livewire roundtrip on every incoming message.
                isActive: () => !!window.__chatOverlayState?.open,
            });
        </script>
    @endscript
</div>
