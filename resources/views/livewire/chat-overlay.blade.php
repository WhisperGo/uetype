<div x-data="chatOverlayDock(@entangle('open'))" class="font-mono">
    {{-- Tombol toggle (bisa digeser): sembunyi saat sesi test/balapan aktif.
         @entangle('open') menyinkron nilai ke server (memicu render konten segar). --}}
    <button x-show="!hidden" x-cloak x-ref="bubble"
        @pointerdown="startDrag($event)"
        :style="bubbleStyle()"
        {{-- Sengaja TIDAK memakai <x-btn-gold>: ini FAB bulat dengan logika drag,
             bukan tombol teks. Memaksakannya ke komponen hanya akan menambah prop
             yang tak dipakai siapa pun. --}}
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
                        {{-- Ukuran mentah: overlay pakai teks 0.65rem, di luar peta btn-ghost. --}}
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

            {{-- Preview reply --}}
            @if ($this->replyingTo)
                <x-chat.reply-preview :message="$this->replyingTo" size="sm" />
            @endif

            <x-chat.composer size="sm" sendFn="window.chatOverlaySend" inputRef="overlayMsgInput" />
        @endif
    </div>

    {{-- MODAL: CLEAR CHAT --}}
    @if ($showClearModal)
        {{-- z di atas drawer overlay (z-[55]) supaya modal tak tertimbun. --}}
        <x-chat.clear-modal z="z-[57]" />
    @endif

    {{-- REAL-TIME: subscription Echo dipegang chatToasts() di layout (satu-satunya
         subscriber). Overlay ini cukup mendengar event window yang diteruskannya.

         Bodi runtime ada di resources/js/chat-runtime.js. Yang TIDAK ikut pindah
         ke sana: window.__chatOverlayState. Flag itu dibaca chatToasts() untuk
         memutuskan kapan toast disupresi, dan HANYA BOLEH ditulis overlay --
         kalau halaman penuh ikut menulisnya, toast tertekan di halaman yang
         salah. Karena itu ia sengaja tinggal di sini, terlihat, bukan tersembunyi
         di modul bersama. --}}
    @script
        <script>
            // Inisialisasi dari nilai $wire saat ini (bukan asumsi default) supaya
            // chatToasts() langsung akurat begitu script jalan, tanpa menunggu watcher
            // pertama. Watcher $watch hanya menyala saat berubah, bukan saat init.
            window.__chatOverlayState = {
                open: $wire.open,
                mode: $wire.activeMode,
                withUsername: $wire.withUsername,
            };

            // Umumkan status buka/tutup & thread aktif tiap kali properti Livewire berubah,
            // supaya chatToasts() di layout tahu kapan mensupresi toast untuk thread ini.
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
                // Drawer tertutup = tak terlihat: jangan gambar bubble ke dalamnya
                // dan jangan picu roundtrip Livewire tiap pesan masuk.
                isActive: () => !!window.__chatOverlayState?.open,
            });
        </script>
    @endscript
</div>
