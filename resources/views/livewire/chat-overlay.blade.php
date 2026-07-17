<div x-data="chatOverlayDock(@entangle('open'))" class="font-mono">
    {{-- Tombol toggle (bisa digeser): sembunyi saat sesi test/balapan aktif.
         @entangle('open') menyinkron nilai ke server (memicu render konten segar). --}}
    <button x-show="!hidden" x-cloak x-ref="bubble"
        @pointerdown="startDrag($event)"
        :style="bubbleStyle()"
        class="fixed z-[56] w-14 h-14 rounded-full bg-gold hover:bg-gold/90 text-background shadow-xl flex items-center justify-center transition-colors touch-none select-none cursor-grab active:cursor-grabbing"
        aria-label="{{ __('chat.title') }}">
        <svg class="w-6 h-6 pointer-events-none" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.17 0-2.29-.2-3.32-.56L3 21l1.56-4.68C3.57 15.19 3 13.65 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" />
        </svg>
        @if ($this->unreadCount > 0)
            <span class="absolute -top-1 -right-1 px-1.5 py-0.5 min-w-[1.25rem] text-center font-mono text-[0.65rem] font-bold text-background bg-danger rounded-full border-2 border-background pointer-events-none">
                {{ $this->unreadCount > 9 ? '9+' : $this->unreadCount }}
            </span>
        @endif
    </button>

    {{-- Drawer: menempel di posisi bubble, dijaga tetap di dalam layar. --}}
    <div x-show="open && !hidden" x-cloak x-ref="panel"
        x-transition:enter="transition ease-out duration-200"
        x-transition:enter-start="opacity-0 translate-y-4"
        x-transition:enter-end="opacity-100 translate-y-0"
        x-transition:leave="transition ease-in duration-150"
        x-transition:leave-start="opacity-100 translate-y-0"
        x-transition:leave-end="opacity-0 translate-y-4"
        :style="panelStyle()"
        class="fixed z-[55] w-96 max-w-[calc(100vw-2.5rem)] h-[32rem] max-h-[70vh] bg-surface border border-white/10 rounded-2xl shadow-2xl flex flex-col overflow-hidden">

        @if ($activeMode === null)
            {{-- PICKER --}}
            <div class="flex items-center justify-between p-4 border-b border-white/5 shrink-0">
                <p class="font-display text-sm text-foreground">{{ __('chat.title') }}</p>
                <div class="flex items-center gap-2">
                    <a href="{{ route('chat.index') }}" wire:navigate class="text-muted hover:text-foreground transition shrink-0 p-1" aria-label="{{ __('chat.open_full') }}" title="{{ __('chat.open_full') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                        </svg>
                    </a>
                    <button @click="open = false" class="text-muted hover:text-foreground shrink-0" aria-label="{{ __('chat.close') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
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
            <div class="flex items-center gap-2 p-3 border-b border-white/5 shrink-0">
                <button wire:click="backToPicker" class="text-muted hover:text-foreground transition shrink-0" aria-label="{{ __('chat.back_to_inbox') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>

                @if ($activeMode === 'dm' && $this->activeFriend)
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        <x-friend-avatar :user="$this->activeFriend" :online="$this->activeFriend->isOnline()" size="w-7 h-7" fallback-size="w-4 h-4" />
                        <p class="font-mono text-xs font-bold text-foreground truncate">{{ $this->activeFriend->username }}</p>
                    </div>
                    <a href="{{ route('chat.index', ['mode' => 'dm', 'with' => $this->activeFriend->username]) }}" wire:navigate class="text-muted hover:text-foreground transition shrink-0 p-1" aria-label="{{ __('chat.open_full') }}" title="{{ __('chat.open_full') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                        </svg>
                    </a>
                @elseif ($activeMode === 'clan' && $this->myClan)
                    <div class="flex items-center gap-2 min-w-0 flex-1">
                        <x-clan-emblem :clan="$this->myClan" size="sm" />
                        <p class="font-mono text-xs font-bold text-foreground truncate">{{ $this->myClan->name }}</p>
                    </div>
                    <a href="{{ route('chat.index', ['mode' => 'clan']) }}" wire:navigate class="text-muted hover:text-foreground transition shrink-0 p-1" aria-label="{{ __('chat.open_full') }}" title="{{ __('chat.open_full') }}">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M4 8V4m0 0h4M4 4l5 5m11-1V4m0 0h-4m4 0l-5 5M4 16v4m0 0h4m-4 0l5-5m11 5l-5-5m5 5v-4m0 4h-4" />
                        </svg>
                    </a>
                @endif

                <button wire:click="openClearModal" class="text-muted hover:text-red-400 transition shrink-0 p-1" aria-label="{{ __('chat.clear_chat') }}" title="{{ __('chat.clear_chat') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                    </svg>
                </button>
                <button @click="open = false" class="text-muted hover:text-foreground shrink-0 p-1" aria-label="{{ __('chat.close') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>

            {{-- Daftar pesan --}}
            <div x-data="chatOverlayScroll()" x-init="init()" id="overlay-chat-messages"
                class="flex-1 overflow-y-auto chat-scroll p-3 space-y-2.5">
                @if ($this->hasMoreOlder)
                    <div class="flex justify-center pb-2">
                        <button wire:click="loadOlder" class="font-mono text-[0.65rem] text-muted hover:text-foreground border border-white/10 rounded-lg px-2.5 py-1 transition">
                            {{ __('chat.load_older') }}
                        </button>
                    </div>
                @endif

                @forelse ($this->messages as $message)
                    @php
                        $mine = $message->sender_id === auth()->id();
                        $deleted = $message->isDeletedForEveryone();
                        $editing = $editingId === $message->id;
                        $canEdit = $mine && $message->canBeEditedBy(auth()->id());
                        $canDeleteEveryone = $mine && $message->canBeDeletedForEveryoneBy(auth()->id());
                    @endphp
                    <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }} group/msg" wire:key="overlay-msg-{{ $message->id }}">
                        <div class="max-w-[85%] {{ $mine ? '' : 'flex flex-col items-start' }}">
                            @if ($activeMode === 'clan' && ! $mine)
                                <p class="font-mono text-[0.6rem] text-muted mb-0.5 px-1">{{ $message->sender->username }}</p>
                            @endif

                            @if ($editing)
                                <form wire:submit.prevent="saveEdit" class="flex items-center gap-1.5">
                                    <input type="text" wire:model="editBody" maxlength="2000" autocomplete="off"
                                        x-init="$nextTick(() => $el.focus())"
                                        @keydown.escape="$wire.cancelEdit()"
                                        class="px-2.5 py-1.5 bg-surface border border-gold/40 rounded-lg font-mono text-xs text-foreground focus:border-gold focus:ring-0 min-w-[8rem]">
                                    <button type="submit" class="text-gold hover:text-gold/80 shrink-0" aria-label="{{ __('chat.edit_save') }}">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    </button>
                                    <button type="button" wire:click="cancelEdit" class="text-muted hover:text-foreground shrink-0" aria-label="{{ __('chat.edit_cancel') }}">
                                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </form>
                            @else
                                <div class="flex items-end gap-1 {{ $mine ? 'flex-row-reverse' : '' }}">
                                    <div class="px-3 py-2 rounded-xl font-mono text-xs break-words
                                        {{ $mine ? 'bg-gold text-background rounded-br-md' : 'bg-white/5 text-foreground rounded-bl-md' }}
                                        {{ $deleted ? 'opacity-60 italic' : '' }}">
                                        @if (! $deleted && $message->reply_to_id && $message->replyTo)
                                            <button type="button" onclick="window.chatOverlayScrollToMessage({{ $message->reply_to_id }})"
                                                class="block w-full text-left mb-1 pl-1.5 border-l-2 rounded-r
                                                    {{ $mine ? 'border-background/40 bg-background/10' : 'border-gold/50 bg-white/5' }} px-1.5 py-0.5">
                                                <span class="block text-[0.6rem] font-bold {{ $mine ? 'text-background/80' : 'text-gold' }}">
                                                    {{ $message->replyTo->sender_id === auth()->id() ? __('chat.you') : $message->replyTo->sender->username }}
                                                </span>
                                                <span class="block text-[0.65rem] opacity-70 truncate">
                                                    {{ $message->replyTo->isDeletedForEveryone() ? __('chat.deleted_placeholder') : Str::limit($message->replyTo->body, 40) }}
                                                </span>
                                            </button>
                                        @endif

                                        @if ($deleted)
                                            <span class="flex items-center gap-1">
                                                <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                                                {{ __('chat.deleted_placeholder') }}
                                            </span>
                                        @else
                                            {{ $message->body }}
                                        @endif
                                        <p class="text-[0.55rem] mt-0.5 opacity-60">
                                            {{ $message->created_at->format('H:i') }}
                                            @if (! $deleted && $message->isEdited())
                                                · {{ __('chat.edited') }}
                                            @endif
                                        </p>
                                    </div>

                                    @unless ($deleted)
                                        <div x-data="{
                                                open: false,
                                                placed: false,
                                                topPx: 0,
                                                toggle() {
                                                    if (this.open) { this.open = false; return; }
                                                    this.placed = false;
                                                    this.open = true;
                                                    this.$nextTick(() => { this.place(); this.placed = true; });
                                                },
                                                place() {
                                                    const box = document.getElementById('overlay-chat-messages');
                                                    const menu = this.$refs.menu;
                                                    if (!box || !menu) return;

                                                    const area = box.getBoundingClientRect();
                                                    const btn = this.$refs.trigger.getBoundingClientRect();
                                                    const h = menu.offsetHeight;
                                                    const gap = 4, pad = 8;

                                                    let top = btn.bottom + gap;
                                                    if (top + h > area.bottom - pad) {
                                                        top = btn.top - gap - h;
                                                    }
                                                    const maxTop = area.bottom - pad - h;
                                                    const minTop = area.top + pad;
                                                    top = Math.max(minTop, Math.min(top, maxTop));

                                                    this.topPx = top - btn.top;
                                                },
                                            }" @click.outside="open = false"
                                            @chat-overlay-scrolled.window="open = false"
                                            class="relative shrink-0">
                                            <button x-ref="trigger" @click="toggle()"
                                                :class="open ? 'bg-gold text-background' : 'bg-white/10 text-foreground hover:bg-gold hover:text-background'"
                                                class="p-1 rounded-full border border-white/10 shadow-sm transition"
                                                aria-label="{{ __('chat.message_actions') }}">
                                                <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z" /></svg>
                                            </button>
                                            <div x-show="open" x-cloak x-ref="menu"
                                                :style="`top: ${topPx}px`"
                                                :class="placed ? 'opacity-100' : 'opacity-0'"
                                                class="absolute z-30 {{ $mine ? 'right-0' : 'left-0' }} w-44 py-1 bg-surface border border-white/10 rounded-xl shadow-lg overflow-hidden transition-opacity duration-150">
                                                <button wire:click="startReply({{ $message->id }})" @click="open = false"
                                                    class="w-full flex items-center gap-2 text-left px-3 py-1.5 font-mono text-[0.7rem] font-semibold text-foreground hover:bg-white/5 transition">
                                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6M3 10l6-6" /></svg>
                                                    {{ __('chat.reply') }}
                                                </button>
                                                @if ($canEdit)
                                                    <button wire:click="startEdit({{ $message->id }})" @click="open = false"
                                                        class="w-full flex items-center gap-2 text-left px-3 py-1.5 font-mono text-[0.7rem] font-semibold text-gold hover:bg-gold/10 transition">
                                                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                                        {{ __('chat.edit') }}
                                                    </button>
                                                @endif
                                                <button wire:click="deleteForMe({{ $message->id }})" @click="open = false"
                                                    class="w-full flex items-center gap-2 text-left px-3 py-1.5 font-mono text-[0.7rem] font-semibold text-amber-400 hover:bg-amber-400/10 transition">
                                                    <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0L21 21" /></svg>
                                                    {{ __('chat.delete_for_me') }}
                                                </button>
                                                @if ($canDeleteEveryone)
                                                    <button wire:click="deleteForEveryone({{ $message->id }})"
                                                        wire:confirm="{{ __('chat.confirm_delete_everyone') }}" @click="open = false"
                                                        class="w-full flex items-center gap-2 text-left px-3 py-1.5 font-mono text-[0.7rem] font-semibold text-red-400 hover:bg-red-400/10 transition">
                                                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" /></svg>
                                                        {{ __('chat.delete_for_everyone') }}
                                                    </button>
                                                @endif
                                            </div>
                                        </div>
                                    @endunless
                                </div>
                            @endif
                        </div>
                    </div>
                @empty
                    <p class="font-mono text-xs text-muted text-center py-8">{{ __('chat.no_messages_yet') }}</p>
                @endforelse
            </div>

            {{-- Preview reply --}}
            @if ($this->replyingTo)
                <div class="flex items-center gap-2 px-3 pt-2 shrink-0">
                    <div class="flex-1 min-w-0 pl-2 border-l-2 border-gold">
                        <p class="font-mono text-[0.6rem] font-bold text-gold">
                            {{ __('chat.replying_to') }}
                            {{ $this->replyingTo->sender_id === auth()->id() ? __('chat.you') : $this->replyingTo->sender->username }}
                        </p>
                        <p class="font-mono text-[0.65rem] text-muted truncate">
                            {{ $this->replyingTo->isDeletedForEveryone() ? __('chat.deleted_placeholder') : Str::limit($this->replyingTo->body, 60) }}
                        </p>
                    </div>
                    <button wire:click="cancelReply" class="text-muted hover:text-foreground shrink-0 p-1" aria-label="{{ __('chat.cancel_reply') }}">
                        <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </button>
                </div>
            @endif

            {{-- Input: fetch() ke /chat/send lewat window.chatOverlaySend, sama pola dengan halaman penuh. --}}
            <form x-data="{ draft: '' }"
                @submit.prevent="
                    const b = draft.trim();
                    if (b === '') return;
                    window.chatOverlaySend(b);
                    draft = '';
                    $refs.overlayMsgInput.focus();
                "
                class="flex items-center gap-2 p-3 border-t border-white/5 shrink-0">
                <input type="text" x-model="draft" x-ref="overlayMsgInput" maxlength="2000" autocomplete="off"
                    placeholder="{{ __('chat.placeholder') }}"
                    class="flex-1 px-3 py-2 bg-surface/40 border border-white/10 rounded-xl font-mono text-xs text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
                <button type="submit"
                    class="px-3 py-2 font-mono text-[0.7rem] font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition disabled:opacity-40"
                    x-bind:disabled="draft.trim() === ''">
                    {{ __('chat.send') }}
                </button>
            </form>
        @endif
    </div>

    {{-- MODAL: CLEAR CHAT --}}
    @if ($showClearModal)
        <div class="fixed inset-0 z-[57] flex items-center justify-center p-4 bg-black/60" wire:click.self="$set('showClearModal', false)">
            <div class="w-full max-w-sm p-6 bg-surface border border-white/10 rounded-2xl">
                <h2 class="font-display text-lg text-foreground mb-1">{{ __('chat.clear_modal_title') }}</h2>
                <p class="font-mono text-xs text-muted mb-5">{{ __('chat.clear_modal_body') }}</p>

                <div class="space-y-3 mb-5">
                    <label class="flex items-center gap-3 font-mono text-sm text-foreground cursor-pointer">
                        <input type="radio" wire:model="clearScope" value="all" class="accent-gold">
                        {{ __('chat.clear_scope_all') }}
                    </label>
                    <label class="flex items-center gap-3 font-mono text-sm text-foreground cursor-pointer">
                        <input type="radio" wire:model="clearScope" value="days" class="accent-gold">
                        <span>{{ __('chat.clear_scope_days') }}</span>
                        <input type="number" wire:model="clearDays" min="1" max="3650"
                            class="w-16 px-2 py-1 bg-surface/60 border border-white/10 rounded-lg font-mono text-sm text-foreground focus:border-gold/50 focus:ring-0">
                        <span>{{ __('chat.clear_scope_days_suffix') }}</span>
                    </label>
                </div>

                <div class="flex justify-end gap-3">
                    <button wire:click="$set('showClearModal', false)"
                        class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                        {{ __('chat.clear_cancel') }}
                    </button>
                    <button wire:click="confirmClear"
                        class="px-4 py-2 font-mono text-xs font-bold text-background bg-red-400 hover:bg-red-400/90 rounded-lg transition">
                        {{ __('chat.clear_confirm') }}
                    </button>
                </div>
            </div>
        </div>
    @endif

    {{-- REAL-TIME: subscription Echo dipegang chatToasts() di layout (satu-satunya
         subscriber). Overlay ini cukup mendengar event window yang diteruskannya,
         dan mengumumkan status buka/tutupnya lewat window.__chatOverlayState agar
         chatToasts() tahu kapan harus mensupresi toast. --}}
    @script
        <script>
            const meId = {{ auth()->id() }};

            const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

            const belongsToOpenOverlayThread = (d) => {
                if (!window.__chatOverlayState?.open) return false;
                if (d.kind === 'dm') {
                    return $wire.activeMode === 'dm' && $wire.withUsername === d.senderUsername;
                }
                if (d.kind === 'clan') {
                    return $wire.activeMode === 'clan';
                }
                return false;
            };

            const appendBubble = (d) => {
                const list = document.getElementById('overlay-chat-messages');
                if (!list) return;
                if (document.querySelector(`[wire\\:key="overlay-msg-${d.messageId}"]`)) return;

                const showName = d.kind === 'clan' && d.senderId !== meId;
                const wrap = document.createElement('div');
                wrap.className = 'flex justify-start';
                wrap.setAttribute('wire:key', `overlay-msg-${d.messageId}`);
                wrap.innerHTML =
                    '<div class="max-w-[85%] flex flex-col items-start">' +
                        (showName ? `<p class="font-mono text-[0.6rem] text-muted mb-0.5 px-1">${esc(d.senderUsername)}</p>` : '') +
                        '<div class="px-3 py-2 rounded-xl font-mono text-xs break-words bg-white/5 text-foreground rounded-bl-md">' +
                            esc(d.body) +
                            '<p class="text-[0.55rem] mt-0.5 opacity-60">' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) + '</p>' +
                        '</div>' +
                    '</div>';
                list.appendChild(wrap);
                requestAnimationFrame(() => { list.scrollTop = list.scrollHeight; });
            };

            let __overlayOptSeq = 0;
            window.__chatOverlayPendingSends = window.__chatOverlayPendingSends || 0;
            window.chatOverlayAppendOutgoing = (body) => {
                const list = document.getElementById('overlay-chat-messages');
                if (!list) return;
                const wrap = document.createElement('div');
                wrap.className = 'flex justify-end';
                wrap.setAttribute('data-optimistic', '1');
                wrap.setAttribute('wire:key', `overlay-opt-${++__overlayOptSeq}`);
                wrap.innerHTML =
                    '<div class="max-w-[85%]">' +
                        '<div class="px-3 py-2 rounded-xl font-mono text-xs break-words bg-gold text-background rounded-br-md opacity-70">' +
                            esc(body) +
                            '<p class="text-[0.55rem] mt-0.5 opacity-60">' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) + '</p>' +
                        '</div>' +
                    '</div>';
                list.appendChild(wrap);
                requestAnimationFrame(() => { list.scrollTop = list.scrollHeight; });
            };

            const csrf = document.querySelector('meta[name="csrf-token"]')?.content;
            let __overlaySyncTimer = null;

            window.chatOverlaySend = (body) => {
                const mode = $wire.activeMode;
                if (!mode) return;

                window.chatOverlayAppendOutgoing(body);

                const replyId = $wire.replyingToId || null;
                if (replyId) $wire.cancelReply();

                window.__chatOverlayPendingSends++;
                fetch(@js(route('chat.send')), {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-TOKEN': csrf,
                        'Accept': 'application/json',
                    },
                    body: JSON.stringify({
                        mode,
                        body,
                        with: $wire.withUsername || null,
                        reply_to_id: replyId,
                    }),
                }).catch(() => {}).finally(() => {
                    window.__chatOverlayPendingSends--;
                    if (window.__chatOverlayPendingSends === 0) {
                        clearTimeout(__overlaySyncTimer);
                        __overlaySyncTimer = setTimeout(() => $wire.dispatch('message-received'), 120);
                    }
                });
            };

            window.chatOverlayScrollToMessage = (id) => {
                const el = document.querySelector(`[wire\\:key="overlay-msg-${id}"]`);
                if (!el) return;
                el.scrollIntoView({ behavior: 'smooth', block: 'center' });
                el.classList.add('chat-flash');
                setTimeout(() => el.classList.remove('chat-flash'), 1200);
            };

            const onRemote = (ev) => {
                const d = ev.detail;
                if (d && belongsToOpenOverlayThread(d)) {
                    appendBubble(d);
                }
                if (window.__chatOverlayState?.open) {
                    $wire.dispatch('message-received');
                }
            };
            window.addEventListener('message-received-remote', onRemote);

            const patchMutation = (d) => {
                const node = document.querySelector(`[wire\\:key="overlay-msg-${d.messageId}"]`);
                if (!node) return false;
                const bubble = node.querySelector('.rounded-xl');
                if (!bubble) return false;

                if (d.action === 'edited') {
                    const timeP = bubble.querySelector('p.opacity-60');
                    [...bubble.childNodes].forEach(n => { if (n !== timeP) n.remove(); });
                    bubble.insertBefore(document.createTextNode(d.body ?? ''), timeP);
                    if (timeP && !timeP.dataset.edited) {
                        timeP.append(` · {{ __('chat.edited') }}`);
                        timeP.dataset.edited = '1';
                    }
                } else if (d.action === 'deleted') {
                    bubble.classList.add('opacity-60', 'italic');
                    bubble.innerHTML =
                        '<span class="flex items-center gap-1">' +
                        '<svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636"/></svg>' +
                        @js(__('chat.deleted_placeholder')) +
                        '</span>';
                }
                return true;
            };
            const onMutated = (ev) => {
                const d = ev.detail;
                if (window.__chatOverlayState?.open && d && d.messageId) patchMutation(d);
                if (window.__chatOverlayState?.open) {
                    $wire.dispatch('message-received');
                }
            };
            window.addEventListener('message-mutated-remote', onMutated);

            document.addEventListener('livewire:navigating', () => {
                window.removeEventListener('message-received-remote', onRemote);
                window.removeEventListener('message-mutated-remote', onMutated);
            }, { once: true });

            if (!window.__chatOverlayScrollHookRegistered) {
                window.__chatOverlayScrollHookRegistered = true;
                Livewire.hook('morph.updated', () => {
                    if (window.__chatOverlayPendingSends === 0) {
                        document.querySelectorAll('#overlay-chat-messages [data-optimistic]').forEach(n => n.remove());
                    }
                    const el = document.getElementById('overlay-chat-messages');
                    if (el) requestAnimationFrame(() => { el.scrollTop = el.scrollHeight; });
                });
            }

            // Inisialisasi dari nilai $wire saat ini (bukan asumsi default) supaya
            // chatToasts() langsung akurat begitu script jalan, tanpa menunggu watcher
            // pertama. Watcher $watch hanya menyala saat berubah, bukan saat init.
            window.__chatOverlayState = {
                open: $wire.open,
                mode: $wire.activeMode,
                withUsername: $wire.withUsername,
            };

            Alpine.data('chatOverlayScroll', () => ({
                init() {
                    this.$nextTick(() => {
                        const el = document.getElementById('overlay-chat-messages');
                        if (!el) return;
                        el.scrollTop = el.scrollHeight;
                        el.addEventListener('scroll', () => {
                            window.dispatchEvent(new CustomEvent('chat-overlay-scrolled'));
                        }, { passive: true });
                    });
                },
            }));

            // Umumkan status buka/tutup & thread aktif tiap kali properti Livewire berubah,
            // supaya chatToasts() di layout tahu kapan mensupresi toast untuk thread ini.
            $wire.$watch('open', (v) => {
                window.__chatOverlayState.open = v;
            });
            $wire.$watch('activeMode', (v) => {
                window.__chatOverlayState.mode = v;
            });
            $wire.$watch('withUsername', (v) => {
                window.__chatOverlayState.withUsername = v;
            });
        </script>
    @endscript

    {{-- Komponen dock: posisi bubble (draggable + clamp ke layar) & hide saat sesi test.
         Didaftarkan sekali via alpine:init; state posisi disimpan di window agar
         bertahan lintas wire:navigate (bubble tak "lompat" balik ke sudut). --}}
    <script>
        if (!window.__chatOverlayDockRegistered) {
            window.__chatOverlayDockRegistered = true;
            document.addEventListener('alpine:init', () => {
                const BUBBLE = 56;   // ukuran tombol (w-14 h-14)
                const MARGIN = 20;   // jarak minimum dari tepi layar (setara bottom-5/right-5)

                window.Alpine.data('chatOverlayDock', (open) => ({
                    open,
                    hidden: false,
                    // Posisi disimpan sebagai JARAK ke tepi terdekat (bukan px absolut dari
                    // sudut kiri-atas). ex/ey = jarak px ke tepi yang dipilih sideX/sideY.
                    // Kebal zoom: saat innerWidth/innerHeight berubah, px absolut dihitung
                    // ULANG dari jarak-tepi ini (lihat resolvePx), jadi bubble tetap menempel
                    // di sudut/sisi yang sama. null = pakai default sudut kanan-bawah.
                    anchor: window.__chatOverlayAnchor || null,
                    // Dependensi reaktif buatan: resolvePx() membaca window.innerWidth/Height
                    // (bukan state Alpine), jadi resize/zoom tak otomatis memicu re-evaluasi
                    // :style. Menaikkan angka ini pada resize memaksa bubbleStyle/panelStyle
                    // dihitung ulang.
                    viewportTick: 0,

                    init() {
                        this.place();
                        window.addEventListener('resize', () => this.reanchor());

                        // Sembunyikan saat sesi ketik/balapan aktif; tutup drawer juga.
                        window.addEventListener('test-activity', (e) => {
                            this.hidden = !!(e.detail && e.detail.active);
                            if (this.hidden) this.open = false;
                        });
                        // Ganti halaman: reset hide (sesi test halaman lama sudah berakhir).
                        document.addEventListener('livewire:navigated', () => { this.hidden = false; });
                    },

                    // Default: sudut kanan-bawah dengan jarak MARGIN dari kedua tepi.
                    place() {
                        if (!this.anchor) {
                            this.anchor = { ex: MARGIN, ey: MARGIN, sideX: 'right', sideY: 'bottom' };
                        }
                        this.reanchor();
                    },

                    // Terjemahkan jarak-tepi -> px absolut kiri-atas memakai viewport SAAT INI,
                    // lalu clamp agar bubble tetap utuh. Dipanggil tiap render & tiap resize/zoom.
                    resolvePx() {
                        void this.viewportTick;
                        const a = this.anchor || { ex: MARGIN, ey: MARGIN, sideX: 'right', sideY: 'bottom' };
                        const maxX = Math.max(MARGIN, window.innerWidth - BUBBLE - MARGIN);
                        const maxY = Math.max(MARGIN, window.innerHeight - BUBBLE - MARGIN);

                        let x = a.sideX === 'right' ? window.innerWidth - BUBBLE - a.ex : a.ex;
                        let y = a.sideY === 'bottom' ? window.innerHeight - BUBBLE - a.ey : a.ey;

                        x = Math.max(MARGIN, Math.min(x, maxX));
                        y = Math.max(MARGIN, Math.min(y, maxY));
                        return { x, y };
                    },

                    // Hitung ulang posisi dari jarak-tepi setelah viewport berubah (resize/zoom):
                    // naikkan viewportTick agar :style dievaluasi ulang, lalu persist anchor.
                    reanchor() {
                        this.viewportTick++;
                        window.__chatOverlayAnchor = this.anchor;
                    },

                    // Ubah px absolut kiri-atas -> model jarak-tepi (pilih tepi terdekat pada
                    // tiap sumbu). Dipakai saat drag selesai supaya posisi baru kebal zoom.
                    pxToAnchor(x, y) {
                        const rightGap = window.innerWidth - BUBBLE - x;
                        const bottomGap = window.innerHeight - BUBBLE - y;
                        const sideX = x <= rightGap ? 'left' : 'right';
                        const sideY = y <= bottomGap ? 'top' : 'bottom';
                        return {
                            ex: Math.max(MARGIN, sideX === 'left' ? x : rightGap),
                            ey: Math.max(MARGIN, sideY === 'top' ? y : bottomGap),
                            sideX,
                            sideY,
                        };
                    },

                    // Satu-satunya penentu buka/tutup: keputusan diambil di pointerup, BUKAN
                    // lewat event click sintetis (yang bisa balapan / tak konsisten antar
                    // browser). Kalau selama gesture pointer bergeser >4px = drag (chat tak
                    // di-toggle); kalau diam = tap (toggle). Jadi menggeser TIDAK PERNAH
                    // membuka/menutup chat.
                    startDrag(e) {
                        // Hanya tombol kiri; abaikan klik kanan/tengah.
                        if (e.button !== undefined && e.button !== 0) return;
                        e.preventDefault();

                        const btn = this.$refs.bubble;
                        const startX = e.clientX, startY = e.clientY;
                        const origin = this.resolvePx();
                        let moved = false;
                        let last = origin;

                        // Pointer capture: semua pointermove/up dialihkan ke tombol ini,
                        // meski kursor keluar dari tombol saat menggeser.
                        try { btn.setPointerCapture(e.pointerId); } catch (_) {}

                        const move = (ev) => {
                            const dx = ev.clientX - startX;
                            const dy = ev.clientY - startY;
                            if (!moved && (Math.abs(dx) > 4 || Math.abs(dy) > 4)) moved = true;
                            if (!moved) return;
                            const maxX = Math.max(MARGIN, window.innerWidth - BUBBLE - MARGIN);
                            const maxY = Math.max(MARGIN, window.innerHeight - BUBBLE - MARGIN);
                            const x = Math.max(MARGIN, Math.min(origin.x + dx, maxX));
                            const y = Math.max(MARGIN, Math.min(origin.y + dy, maxY));
                            last = { x, y };
                            // Selama gesture pakai anchor kiri-atas sementara (gerak halus).
                            this.anchor = { ex: x, ey: y, sideX: 'left', sideY: 'top' };
                        };
                        const up = (ev) => {
                            window.removeEventListener('pointermove', move);
                            window.removeEventListener('pointerup', up);
                            try { btn.releasePointerCapture(ev.pointerId); } catch (_) {}
                            if (!moved) {
                                // Tap -> toggle chat.
                                this.open = ! this.open;
                            } else {
                                // Drag selesai -> kunci ke tepi terdekat (kebal zoom) & persist.
                                this.anchor = this.pxToAnchor(last.x, last.y);
                                window.__chatOverlayAnchor = this.anchor;
                            }
                        };
                        window.addEventListener('pointermove', move);
                        window.addEventListener('pointerup', up);
                    },

                    // Object-form :style (bukan string) supaya Alpine MERGE properti posisi
                    // dan tak menimpa `display` yang dikelola x-show — kalau string, x-show
                    // yang menyembunyikan bubble/panel akan ter-clobber tiap :style re-run.
                    bubbleStyle() {
                        const p = this.resolvePx();
                        return { left: p.x + 'px', top: p.y + 'px', right: 'auto', bottom: 'auto' };
                    },

                    // Drawer menempel ke bubble, lalu di-clamp agar tak keluar layar.
                    // Buka ke atas kalau ruang di bawah kurang; geser kiri kalau mepet kanan.
                    panelStyle() {
                        const p = this.resolvePx();
                        const gap = 12;
                        const panel = this.$refs.panel;
                        const pw = panel?.offsetWidth || Math.min(384, window.innerWidth - MARGIN * 2);
                        const ph = panel?.offsetHeight || Math.min(512, window.innerHeight * 0.7);

                        // Kanan-selaraskan drawer dengan bubble; clamp horizontal.
                        let left = p.x + BUBBLE - pw;
                        left = Math.max(MARGIN, Math.min(left, window.innerWidth - pw - MARGIN));

                        // Default buka ke atas bubble; kalau tak muat, buka ke bawah.
                        let top = p.y - gap - ph;
                        if (top < MARGIN) {
                            const below = p.y + BUBBLE + gap;
                            top = (below + ph <= window.innerHeight - MARGIN) ? below : MARGIN;
                        }
                        top = Math.max(MARGIN, Math.min(top, window.innerHeight - ph - MARGIN));

                        return { left: left + 'px', top: top + 'px', right: 'auto', bottom: 'auto' };
                    },
                }));
            });
        }
    </script>
</div>
