<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <h1 class="font-display text-2xl tracking-wide text-foreground mb-6">{{ __('chat.title') }}</h1>

    @if ($activeMode === null)
        {{-- ===================================================================== --}}
        {{-- TABS: Friends | Clan --}}
        {{-- ===================================================================== --}}
        <div class="border-b border-white/10 mb-6">
            <nav class="flex gap-6 -mb-px font-mono text-sm" aria-label="Chat tabs">
                <button wire:click="$set('activeMode', null)"
                    class="px-1 py-3 border-b-2 border-gold text-foreground font-semibold whitespace-nowrap">
                    {{ __('chat.tab_friends') }}
                </button>
            </nav>
        </div>

        {{-- INBOX: daftar percakapan DM --}}
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
            <div class="flex flex-col items-center justify-center py-16 text-center select-none">
                <img src="/icon/uetype_mascot.png" alt="" class="w-16 h-16 opacity-30 mb-4">
                <p class="font-mono text-sm font-bold text-foreground">{{ __('chat.empty_inbox_title') }}</p>
                <p class="font-mono text-xs text-muted mt-1">{{ __('chat.empty_inbox_body') }}</p>
                <a href="{{ route('friends.index') }}" wire:navigate
                    class="mt-5 px-5 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                    {{ __('chat.go_to_friends') }}
                </a>
            </div>
        @endif

        {{-- CLAN CHAT: kartu pintasan (bukan tab terpisah -- clan cuma satu). --}}
        <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('chat.tab_clan') }}</p>
        @if ($this->myClan)
            <button wire:click="openClanChat"
                class="w-full flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl hover:border-white/10 transition text-left group">
                <x-clan-emblem :clan="$this->myClan" size="sm" />
                <div class="flex-1 min-w-0">
                    <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $this->myClan->name }}</p>
                    <p class="font-mono text-xs text-muted mt-0.5">{{ __('chat.members', ['count' => $this->myClan->activeMembers()->count()]) }}</p>
                </div>
                <span class="px-3 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition shrink-0">
                    {{ __('chat.open_clan_chat') }}
                </span>
            </button>
        @else
            <div class="flex flex-col items-center justify-center py-12 text-center select-none border bg-surface/20 border-white/5 rounded-2xl">
                <p class="font-mono text-sm font-bold text-foreground">{{ __('chat.no_clan_title') }}</p>
                <p class="font-mono text-xs text-muted mt-1">{{ __('chat.no_clan_body') }}</p>
                <a href="{{ route('clans.index') }}" wire:navigate
                    class="mt-4 px-5 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                    {{ __('chat.go_to_clans') }}
                </a>
            </div>
        @endif
    @else
        {{-- ===================================================================== --}}
        {{-- JENDELA OBROLAN AKTIF (DM atau Clan) --}}
        {{-- ===================================================================== --}}
        <div class="border bg-surface/40 border-white/5 rounded-3xl flex flex-col h-[70vh]">
            {{-- Header --}}
            <div class="flex items-center gap-3 p-4 border-b border-white/5 shrink-0">
                <button wire:click="closeConversation" class="text-muted hover:text-foreground transition shrink-0" aria-label="{{ __('chat.back_to_inbox') }}">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                    </svg>
                </button>

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

                <button wire:click="openClearModal" class="text-muted hover:text-red-400 transition shrink-0 p-2" aria-label="{{ __('chat.clear_chat') }}" title="{{ __('chat.clear_chat') }}">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" />
                    </svg>
                </button>
            </div>

            {{-- Daftar pesan --}}
            <div x-data="chatScroll()" x-init="init()" id="chat-messages"
                class="flex-1 overflow-y-auto p-4 space-y-3">
                @if ($this->hasMoreOlder)
                    <div class="flex justify-center pb-2">
                        <button wire:click="loadOlder" class="font-mono text-xs text-muted hover:text-foreground border border-white/10 rounded-lg px-3 py-1.5 transition">
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
                    <div class="flex {{ $mine ? 'justify-end' : 'justify-start' }} group/msg" wire:key="msg-{{ $message->id }}">
                        <div class="max-w-[75%] {{ $mine ? '' : 'flex flex-col items-start' }}">
                            @if ($activeMode === 'clan' && ! $mine)
                                <p class="font-mono text-[0.65rem] text-muted mb-1 px-1">{{ $message->sender->username }}</p>
                            @endif

                            @if ($editing)
                                {{-- Form edit inline --}}
                                <form wire:submit.prevent="saveEdit" class="flex items-center gap-2">
                                    <input type="text" wire:model="editBody" maxlength="2000" autocomplete="off"
                                        x-init="$nextTick(() => $el.focus())"
                                        @keydown.escape="$wire.cancelEdit()"
                                        class="px-3 py-2 bg-surface border border-gold/40 rounded-xl font-mono text-sm text-foreground focus:border-gold focus:ring-0 min-w-[12rem]">
                                    <button type="submit" class="text-gold hover:text-gold/80 shrink-0" aria-label="{{ __('chat.edit_save') }}" title="{{ __('chat.edit_save') }}">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                    </button>
                                    <button type="button" wire:click="cancelEdit" class="text-muted hover:text-foreground shrink-0" aria-label="{{ __('chat.edit_cancel') }}" title="{{ __('chat.edit_cancel') }}">
                                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                                    </button>
                                </form>
                            @else
                                <div class="flex items-end gap-1.5 {{ $mine ? 'flex-row-reverse' : '' }}">
                                    <div class="px-4 py-2.5 rounded-2xl font-mono text-sm break-words
                                        {{ $mine ? 'bg-gold text-background rounded-br-md' : 'bg-white/5 text-foreground rounded-bl-md' }}
                                        {{ $deleted ? 'opacity-60 italic' : '' }}">
                                        @if ($deleted)
                                            <span class="flex items-center gap-1.5">
                                                <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                                                {{ __('chat.deleted_placeholder') }}
                                            </span>
                                        @else
                                            {{ $message->body }}
                                        @endif
                                        <p class="text-[0.6rem] mt-1 opacity-60">
                                            {{ $message->created_at->format('H:i') }}
                                            @if (! $deleted && $message->isEdited())
                                                · {{ __('chat.edited') }}
                                            @endif
                                        </p>
                                    </div>

                                    {{-- Menu aksi per-pesan (muncul saat hover). Tak muncul untuk
                                         pesan yang sudah dihapus-untuk-semua. --}}
                                    @unless ($deleted)
                                        <div x-data="{
                                                open: false,
                                                up: false,
                                                toggle() {
                                                    if (this.open) { this.open = false; return; }
                                                    // Tentukan arah buka menu dari ruang tersisa di dalam kotak chat:
                                                    // kalau ruang di BAWAH tombol cukup, buka ke bawah (default,
                                                    // supaya pesan pertama/paling atas tak menembus header);
                                                    // kalau mepet ke dasar, baru buka ke atas.
                                                    const box = document.getElementById('chat-messages');
                                                    const btn = this.$refs.trigger.getBoundingClientRect();
                                                    const area = box.getBoundingClientRect();
                                                    const spaceBelow = area.bottom - btn.bottom;
                                                    this.up = spaceBelow < 180; // tinggi menu ± 3 item
                                                    this.open = true;
                                                },
                                            }" @click.outside="open = false" class="relative shrink-0">
                                            <button x-ref="trigger" @click="toggle()"
                                                :class="open ? 'bg-gold text-background' : 'bg-white/10 text-foreground hover:bg-gold hover:text-background'"
                                                class="p-1.5 rounded-full border border-white/10 shadow-sm transition"
                                                aria-label="{{ __('chat.message_actions') }}">
                                                <svg class="w-4 h-4" fill="currentColor" viewBox="0 0 20 20"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z" /></svg>
                                            </button>
                                            <div x-show="open" x-cloak x-transition
                                                :class="up ? 'bottom-full mb-1' : 'top-full mt-1'"
                                                class="absolute z-30 {{ $mine ? 'right-0' : 'left-0' }} w-48 py-1 bg-surface border border-white/10 rounded-xl shadow-lg overflow-hidden">
                                                @if ($canEdit)
                                                    <button wire:click="startEdit({{ $message->id }})" @click="open = false"
                                                        class="w-full flex items-center gap-2.5 text-left px-3 py-2 font-mono text-xs font-semibold text-gold hover:bg-gold/10 transition">
                                                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                                        {{ __('chat.edit') }}
                                                    </button>
                                                @endif
                                                <button wire:click="deleteForMe({{ $message->id }})" @click="open = false"
                                                    class="w-full flex items-center gap-2.5 text-left px-3 py-2 font-mono text-xs font-semibold text-amber-400 hover:bg-amber-400/10 transition">
                                                    <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0L21 21" /></svg>
                                                    {{ __('chat.delete_for_me') }}
                                                </button>
                                                @if ($canDeleteEveryone)
                                                    <button wire:click="deleteForEveryone({{ $message->id }})"
                                                        wire:confirm="{{ __('chat.confirm_delete_everyone') }}" @click="open = false"
                                                        class="w-full flex items-center gap-2.5 text-left px-3 py-2 font-mono text-xs font-semibold text-red-400 hover:bg-red-400/10 transition">
                                                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" /></svg>
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
                    <p class="font-mono text-sm text-muted text-center py-10">{{ __('chat.no_messages_yet') }}</p>
                @endforelse
            </div>

            {{-- Input --}}
            {{-- Tombol enable/disable dihitung di CLIENT via Alpine (bukan
                 @disabled server-side) supaya berubah seketika saat mengetik,
                 tanpa menunggu round-trip Livewire. wire:model.live tetap
                 dipakai agar $body server ikut sinkron untuk validasi. --}}
            {{-- draft = @entangle('body') (BUKAN .live) supaya server tetap
                 sumber kebenaran $body: Livewire men-sync input ke $body
                 sebelum menjalankan sendMessage, lalu sendMessage yang
                 mengosongkan $body dan entangle memantulkannya balik ke draft.
                 Optimistic append TIDAK menyentuh draft, jadi $body tak pernah
                 keburu kosong saat action jalan. --}}
            <form wire:submit.prevent="sendMessage" x-data="{ draft: @entangle('body') }"
                @submit="if (draft.trim() !== '') { window.chatAppendOutgoing(draft.trim()); }"
                class="flex items-center gap-3 p-4 border-t border-white/5 shrink-0">
                <input type="text" x-model="draft" maxlength="2000" autocomplete="off"
                    placeholder="{{ __('chat.placeholder') }}"
                    class="flex-1 px-4 py-2.5 bg-surface/40 border border-white/10 rounded-2xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
                <button type="submit"
                    class="px-5 py-2.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition disabled:opacity-40"
                    x-bind:disabled="draft.trim() === ''">
                    {{ __('chat.send') }}
                </button>
            </form>
        </div>
    @endif

    {{-- ===== MODAL: CLEAR CHAT ===== --}}
    @if ($showClearModal)
        <div class="fixed inset-0 z-50 flex items-center justify-center p-4 bg-black/60" wire:click.self="$set('showClearModal', false)">
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

    {{-- ===== REAL-TIME =====
         Subscription Echo ke chat.{id}/clan-chat.{clanId} DIPEGANG oleh toast
         global di layout (satu-satunya subscriber, agar tak dobel). Halaman
         ini cukup mendengar event window yang diteruskan toast lalu
         menyegarkan datanya. --}}
    @script
        <script>
            const meId = {{ auth()->id() }};

            // Escape teks pesan sebelum dimasukkan ke DOM (payload dari WebSocket
            // = input user lain, jangan pernah dianggap HTML aman).
            const esc = (s) => { const d = document.createElement('div'); d.textContent = s ?? ''; return d.innerHTML; };

            // Apakah pesan yang masuk termasuk percakapan yang SEDANG dibuka?
            const belongsToOpenConversation = (d) => {
                if (d.kind === 'dm') {
                    // DM masuk dari lawan bicara: relevan kalau mode dm & pengirim = lawan yang dibuka.
                    return $wire.activeMode === 'dm' && $wire.withUsername === d.senderUsername;
                }
                if (d.kind === 'clan') {
                    return $wire.activeMode === 'clan';
                }
                return false;
            };

            // Tempel bubble pesan LANGSUNG ke DOM dari payload WebSocket, tanpa
            // menunggu render server. Livewire lalu rekonsiliasi di belakang
            // layar; karena id (wire:key) sama, node ini tak akan terduplikasi.
            const appendBubble = (d) => {
                const list = document.getElementById('chat-messages');
                if (!list) return;
                if (document.querySelector(`[wire\\:key="msg-${d.messageId}"]`)) return; // sudah ada

                const showName = d.kind === 'clan' && d.senderId !== meId;
                const wrap = document.createElement('div');
                wrap.className = 'flex justify-start';
                wrap.setAttribute('wire:key', `msg-${d.messageId}`);
                wrap.innerHTML =
                    '<div class="max-w-[75%] flex flex-col items-start">' +
                        (showName ? `<p class="font-mono text-[0.65rem] text-muted mb-1 px-1">${esc(d.senderUsername)}</p>` : '') +
                        '<div class="px-4 py-2.5 rounded-2xl font-mono text-sm break-words bg-white/5 text-foreground rounded-bl-md">' +
                            esc(d.body) +
                            '<p class="text-[0.6rem] mt-1 opacity-60">' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) + '</p>' +
                        '</div>' +
                    '</div>';
                list.appendChild(wrap);
                requestAnimationFrame(() => { list.scrollTop = list.scrollHeight; });
            };

            // Pesan KELUAR (milik sendiri) ditampilkan seketika saat submit,
            // sebelum server merespons. Tak diberi wire:key -- ditandai
            // data-optimistic; saat Livewire morph membawa bubble asli (dengan
            // id DB), node sementara ini otomatis tergantikan (tanpa duplikat).
            window.chatAppendOutgoing = (body) => {
                const list = document.getElementById('chat-messages');
                if (!list) return;
                const wrap = document.createElement('div');
                wrap.className = 'flex justify-end';
                wrap.setAttribute('data-optimistic', '1');
                wrap.innerHTML =
                    '<div class="max-w-[75%]">' +
                        '<div class="px-4 py-2.5 rounded-2xl font-mono text-sm break-words bg-gold text-background rounded-br-md opacity-70">' +
                            esc(body) +
                            '<p class="text-[0.6rem] mt-1 opacity-60">' + new Date().toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', hour12: false }) + '</p>' +
                        '</div>' +
                    '</div>';
                list.appendChild(wrap);
                requestAnimationFrame(() => { list.scrollTop = list.scrollHeight; });
            };

            const onRemote = (ev) => {
                const d = ev.detail;
                // Optimistic: kalau pesan untuk percakapan yang sedang dibuka,
                // tampilkan seketika supaya tak terasa delay.
                if (d && belongsToOpenConversation(d)) {
                    appendBubble(d);
                }
                // Tetap sinkronkan state otoritatif (read receipt, dedup, dsb).
                $wire.dispatch('message-received');
            };
            window.addEventListener('message-received-remote', onRemote);

            // Edit/hapus pesan dari sisi lain: cukup minta Livewire re-render
            // supaya bubble memperbarui isinya / jadi placeholder / label edited.
            const onMutated = () => $wire.dispatch('message-received');
            window.addEventListener('message-mutated-remote', onMutated);

            document.addEventListener('livewire:navigating', () => {
                window.removeEventListener('message-received-remote', onRemote);
                window.removeEventListener('message-mutated-remote', onMutated);
            }, { once: true });

            // Auto-scroll ke pesan terbaru saat jendela obrolan pertama kali
            // dibuka DAN setiap kali daftar pesan berubah (pesan baru masuk/keluar).
            // Hook Livewire didaftarkan SEKALI secara global (bukan di init()
            // tiap instance x-data) supaya tak menumpuk listener tiap kali
            // jendela obrolan dibuka/tutup.
            if (!window.__chatScrollHookRegistered) {
                window.__chatScrollHookRegistered = true;
                Livewire.hook('morph.updated', () => {
                    const el = document.getElementById('chat-messages');
                    if (el) requestAnimationFrame(() => { el.scrollTop = el.scrollHeight; });
                });
            }

            Alpine.data('chatScroll', () => ({
                init() {
                    this.$nextTick(() => {
                        const el = document.getElementById('chat-messages');
                        if (el) el.scrollTop = el.scrollHeight;
                    });
                },
            }));
        </script>
    @endscript
</div>
