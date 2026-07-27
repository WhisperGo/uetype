{{-- Full chat page: DM inbox + clan-chat shortcut, and the active conversation view
     (message list, reply preview, composer). Real-time updates arrive via window events
     relayed by the global toast subscriber in the layout. --}}
<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <h1 class="font-display text-fluid-title tracking-wide text-foreground mb-4">{{ __('chat.title') }}</h1>

    @if ($activeMode === null)
        {{-- TABS: Friends | Clan --}}
        <div class="border-b border-white/10 mb-6">
            <nav class="flex gap-6 -mb-px font-mono text-sm" aria-label="{{ __('chat.tab_aria') }}">
                <button wire:click="$set('activeMode', null)"
                    class="px-1 py-3 border-b-2 border-gold text-foreground font-semibold whitespace-nowrap">
                    {{ __('chat.tab_friends') }}
                </button>
            </nav>
        </div>

        {{-- INBOX: list of DM conversations --}}
        @if ($this->conversations->count() > 0)
            <div class="space-y-3 mb-8">
                @foreach ($this->conversations as $row)
                    <button wire:click="openDm('{{ $row['user']->username }}')"
                        class="w-full flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl hover:border-white/10 transition text-left group"
                        wire:key="conv-{{ $row['user']->id }}">
                        <x-friend-avatar :user="$row['user']" :online="$row['online']" />
                        <div class="flex-1 min-w-0">
                            <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $row['user']->username }}</p>
                            <p class="font-mono text-xs text-muted mt-0.5 truncate">
                                @if ($row['lastMessage'])
                                    @if ($row['lastMessage']->sender_id !== $row['user']->id)
                                        <span class="text-muted/70">{{ __('chat.you') }}:</span>
                                    @endif
                                    {{ Str::limit($row['lastMessage']->body, 60) }}
                                @else
                                    {{ __('chat.no_messages_yet') }}
                                @endif
                            </p>
                        </div>
                        @if ($row['unreadCount'] > 0)
                            <span class="px-2 py-0.5 min-w-[1.5rem] text-center font-mono text-xs font-bold text-background bg-gold rounded-full shrink-0">
                                {{ $row['unreadCount'] }}
                            </span>
                        @endif
                    </button>
                @endforeach
            </div>
        @else
            <x-empty-state spacing="16" :title="__('chat.empty_inbox_title')" :body="__('chat.empty_inbox_body')">
                <x-slot:cta>
                    <x-btn-gold as="a" size="lg" href="{{ route('friends.index') }}" wire:navigate>
                        {{ __('chat.go_to_friends') }}
                    </x-btn-gold>
                </x-slot:cta>
            </x-empty-state>
        @endif

        {{-- CLAN CHAT: shortcut card (there's only one clan, so no separate tab). --}}
        <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('chat.tab_clan') }}</p>
        @if ($this->myClan)
            <button wire:click="openClanChat"
                class="w-full flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl hover:border-white/10 transition text-left group">
                <x-clan-emblem :clan="$this->myClan" size="sm" />
                <div class="flex-1 min-w-0">
                    <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $this->myClan->name }}</p>
                    <p class="font-mono text-xs text-muted mt-0.5">{{ __('chat.members', ['count' => $this->myClan->activeMembers()->count()]) }}</p>
                </div>
                <x-btn-gold as="span" size="sm" class="shrink-0">{{ __('chat.open_clan_chat') }}</x-btn-gold>
            </button>
        @else
            <div class="flex flex-col items-center justify-center py-12 text-center select-none border bg-surface/20 border-white/5 rounded-2xl">
                <p class="font-mono text-sm font-bold text-foreground">{{ __('chat.no_clan_title') }}</p>
                <p class="font-mono text-xs text-muted mt-1">{{ __('chat.no_clan_body') }}</p>
                <x-btn-gold as="a" size="lg" class="mt-4" href="{{ route('clans.index') }}" wire:navigate>
                    {{ __('chat.go_to_clans') }}
                </x-btn-gold>
            </div>
        @endif
    @else
        {{-- ACTIVE CONVERSATION WINDOW (DM or Clan) --}}
        <div class="border bg-surface/40 border-white/5 rounded-3xl flex flex-col h-[70vh]">
            {{-- Header --}}
            {{-- Padding stays `p-4` -- the header height is the prototype's. The 44px buttons
                 pull their own click box back out of the flow with `-my-2`, so the row keeps
                 the height it always had. --}}
            <div class="flex items-center gap-3 p-4 border-b border-white/5 shrink-0">
                <x-icon-button wire:click="closeConversation" class="-my-2 -ms-2" :label="__('chat.back_to_inbox')">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </x-icon-button>

                @if ($activeMode === 'dm' && $this->activeFriend)
                    <a href="{{ route('profile.show', $this->activeFriend) }}" wire:navigate class="flex items-center gap-3 min-w-0 flex-1">
                        <x-friend-avatar :user="$this->activeFriend" :online="$this->activeFriend->isOnline()" />
                        <div class="min-w-0">
                            <p class="font-mono text-sm font-bold text-foreground truncate">{{ $this->activeFriend->username }}</p>
                            <p class="font-mono text-xs mt-0.5">
                                @if ($this->activeFriend->isOnline())
                                    <span class="text-active">{{ __('chat.online') }}</span>
                                @elseif ($this->activeFriend->last_seen_at)
                                    <span class="text-muted">{{ __('chat.last_seen', ['time' => $this->activeFriend->last_seen_at->diffForHumans()]) }}</span>
                                @else
                                    <span class="text-muted">{{ __('chat.offline') }}</span>
                                @endif
                            </p>
                        </div>
                    </a>
                @elseif ($activeMode === 'clan' && $this->myClan)
                    <div class="flex items-center gap-3 min-w-0 flex-1">
                        <x-clan-emblem :clan="$this->myClan" size="sm" />
                        <div class="min-w-0">
                            <p class="font-mono text-sm font-bold text-foreground truncate">{{ $this->myClan->name }}</p>
                            <p class="font-mono text-xs text-muted mt-0.5">{{ __('chat.members', ['count' => $this->myClan->activeMembers()->count()]) }}</p>
                        </div>
                    </div>
                @endif

                {{-- Destructive, so `tone="danger"` -- see the same pairing in the overlay header. --}}
                <x-icon-button wire:click="openClearModal" tone="danger" class="-my-2 -me-2" :label="__('chat.clear_chat')">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                    </svg>
                </x-icon-button>
            </div>

            {{-- Message list --}}
            <div x-data="chatScroll()" x-init="init()" id="chat-messages"
                class="flex-1 overflow-y-auto chat-scroll p-4 space-y-3">
                @if ($this->hasMoreOlder)
                    <div class="flex justify-center pb-2">
                        <x-btn-ghost size="sm" wire:click="loadOlder">{{ __('chat.load_older') }}</x-btn-ghost>
                    </div>
                @endif

                @forelse ($this->messages as $message)
                    <x-chat.message :message="$message" :active-mode="$activeMode" :editing-id="$editingId"
                        size="lg" keyPrefix="msg" containerId="chat-messages"
                        scrollEvent="chat-scrolled" scrollFn="window.chatScrollToMessage" />
                @empty
                    <p class="font-mono text-sm text-muted text-center py-10">{{ __('chat.no_messages_yet') }}</p>
                @endforelse
            </div>

            {{-- Preview of the message being replied to (above the input). --}}
            @if ($this->replyingTo)
                <x-chat.reply-preview :message="$this->replyingTo" size="lg" />
            @endif

            <x-chat.composer size="lg" sendFn="window.chatSend" inputRef="msgInput" />
        </div>
    @endif

    {{-- ===== MODAL: CLEAR CHAT ===== --}}
    @if ($showClearModal)
        <x-chat.clear-modal z="z-50" />
    @endif

    {{-- REAL-TIME: the Echo subscription is owned by the global toast in the layout
         (the only subscriber). This page just listens to the window events it relays.

         The body lives in resources/js/chat-runtime.js; this @script only injects the
         four values that only Blade knows. --}}
    @script
        <script>
            window.createChatRuntime({
                variant: 'page',
                wire: $wire,
                meId: {{ auth()->id() }},
                sendUrl: @js(route('chat.send')),
                labels: {
                    edited: @js(__('chat.edited')),
                    deleted: @js(__('chat.deleted_placeholder')),
                },
            });
        </script>
    @endscript
</div>
