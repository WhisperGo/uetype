@props([
    'message',
    'activeMode',
    'editingId',
    // 'lg' = halaman penuh, 'sm' = overlay drawer.
    'size' => 'lg',
    // Prefix wire:key. WAJIB berbeda antar varian: di /chat kedua komponen hidup
    // bersamaan, dan key yang sama membuat morph Livewire menukar bubble antar
    // komponen.
    'keyPrefix' => 'msg',
    // id container daftar pesan; dipakai menu aksi untuk mengukur ruang.
    'containerId' => 'chat-messages',
    // Event window yang menutup menu aksi saat daftar digulir.
    'scrollEvent' => 'chat-scrolled',
    // Fungsi global untuk melompat ke pesan yang dikutip.
    'scrollFn' => 'window.chatScrollToMessage',
])

@php
    $mine = $message->sender_id === auth()->id();
    $deleted = $message->isDeletedForEveryone();
    $editing = $editingId === $message->id;
    $canEdit = $mine && $message->canBeEditedBy(auth()->id());
    $canDeleteEveryone = $mine && $message->canBeDeletedForEveryoneBy(auth()->id());

    // Satu-satunya perbedaan nyata antara kedua varian adalah token ukuran.
    // Logika PHP di atas identik -- itulah sebabnya keduanya bisa disatukan.
    $t = $size === 'sm'
        ? [
            'wrap' => 'max-w-[85%]',
            'name' => 'text-[0.6rem] mb-0.5',
            'editGap' => 'gap-1.5',
            'editInput' => 'px-2.5 py-1.5 rounded-lg text-xs min-w-[8rem]',
            'editIcon' => 'w-4 h-4',
            'row' => 'gap-1',
            'bubble' => 'px-3 py-2 rounded-xl text-xs',
            'quote' => 'mb-1 pl-1.5 px-1.5 py-0.5',
            'quoteName' => 'text-[0.6rem]',
            'quoteBody' => 'text-[0.65rem]',
            'quoteLimit' => 40,
            'delRow' => 'gap-1',
            'delIcon' => 'w-3 h-3',
            'time' => 'text-[0.55rem] mt-0.5',
            'trigger' => 'p-1',
            'triggerIcon' => 'w-3.5 h-3.5',
            'menu' => 'w-44',
            'item' => 'gap-2 px-3 py-1.5 text-[0.7rem]',
            'itemIcon' => 'w-3.5 h-3.5',
        ]
        : [
            'wrap' => 'max-w-[75%]',
            'name' => 'text-[0.65rem] mb-1',
            'editGap' => 'gap-2',
            'editInput' => 'px-3 py-2 rounded-xl text-sm min-w-[12rem]',
            'editIcon' => 'w-5 h-5',
            'row' => 'gap-1.5',
            'bubble' => 'px-4 py-2.5 rounded-2xl text-sm',
            'quote' => 'mb-1.5 pl-2 px-2 py-1',
            'quoteName' => 'text-[0.65rem]',
            'quoteBody' => 'text-[0.7rem]',
            'quoteLimit' => 60,
            'delRow' => 'gap-1.5',
            'delIcon' => 'w-3.5 h-3.5',
            'time' => 'text-[0.6rem] mt-1',
            'trigger' => 'p-1.5',
            'triggerIcon' => 'w-4 h-4',
            'menu' => 'w-48',
            'item' => 'gap-2.5 px-3 py-2 text-xs',
            'itemIcon' => 'w-4 h-4',
        ];
@endphp

<div class="flex {{ $mine ? 'justify-end' : 'justify-start' }} group/msg" wire:key="{{ $keyPrefix }}-{{ $message->id }}">
    <div class="{{ $t['wrap'] }} {{ $mine ? '' : 'flex flex-col items-start' }}">
        @if ($activeMode === 'clan' && ! $mine)
            <p class="font-mono {{ $t['name'] }} text-muted px-1">{{ $message->sender->username }}</p>
        @endif

        @if ($editing)
            {{-- Form edit inline --}}
            <form wire:submit.prevent="saveEdit" class="flex items-center {{ $t['editGap'] }}">
                <input type="text" wire:model="editBody" maxlength="2000" autocomplete="off"
                    x-init="$nextTick(() => $el.focus())"
                    @keydown.escape="$wire.cancelEdit()"
                    class="{{ $t['editInput'] }} bg-surface border border-gold/40 font-mono text-foreground focus:border-gold focus:ring-0">
                <button type="submit" class="text-gold hover:text-gold/80 shrink-0" aria-label="{{ __('chat.edit_save') }}" title="{{ __('chat.edit_save') }}">
                    <svg class="{{ $t['editIcon'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                </button>
                <button type="button" wire:click="cancelEdit" class="text-muted hover:text-foreground shrink-0" aria-label="{{ __('chat.edit_cancel') }}" title="{{ __('chat.edit_cancel') }}">
                    <svg class="{{ $t['editIcon'] }}" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </form>
        @else
            <div class="flex items-end {{ $t['row'] }} {{ $mine ? 'flex-row-reverse' : '' }}">
                <div class="{{ $t['bubble'] }} font-mono break-words
                    {{ $mine ? 'bg-gold text-background rounded-br-md' : 'bg-white/5 text-foreground rounded-bl-md' }}
                    {{ $deleted ? 'opacity-60 italic' : '' }}">
                    {{-- Kutipan pesan yang dibalas (kalau ini reply & pesan asli masih ada). --}}
                    @if (! $deleted && $message->reply_to_id && $message->replyTo)
                        <button type="button" onclick="{{ $scrollFn }}({{ $message->reply_to_id }})"
                            class="block w-full text-left {{ $t['quote'] }} border-l-2 rounded-r
                                {{ $mine ? 'border-background/40 bg-background/10' : 'border-gold/50 bg-white/5' }}">
                            <span class="block {{ $t['quoteName'] }} font-bold {{ $mine ? 'text-background/80' : 'text-gold' }}">
                                {{ $message->replyTo->sender_id === auth()->id() ? __('chat.you') : $message->replyTo->sender->username }}
                            </span>
                            <span class="block {{ $t['quoteBody'] }} opacity-70 truncate">
                                {{ $message->replyTo->isDeletedForEveryone() ? __('chat.deleted_placeholder') : Str::limit($message->replyTo->body, $t['quoteLimit']) }}
                            </span>
                        </button>
                    @endif

                    @if ($deleted)
                        <span class="flex items-center {{ $t['delRow'] }}">
                            <svg class="{{ $t['delIcon'] }} shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18.364 18.364A9 9 0 005.636 5.636m12.728 12.728A9 9 0 015.636 5.636m12.728 12.728L5.636 5.636" /></svg>
                            {{ __('chat.deleted_placeholder') }}
                        </span>
                    @else
                        {{ $message->body }}
                    @endif
                    <p class="{{ $t['time'] }} opacity-60">
                        {{ $message->created_at->format('H:i') }}
                        @if (! $deleted && $message->isEdited())
                            · {{ __('chat.edited') }}
                        @endif
                    </p>
                </div>

                {{-- Menu aksi per-pesan; tak muncul untuk pesan yang sudah dihapus-untuk-semua. --}}
                @unless ($deleted)
                    <div x-data="{
                            open: false,
                            placed: false,
                            topPx: 0,
                            toggle() {
                                if (this.open) { this.open = false; return; }
                                this.placed = false;
                                this.open = true;
                                // Posisikan setelah dirender agar tingginya terukur (2-4 item).
                                this.$nextTick(() => { this.place(); this.placed = true; });
                            },
                            // Buka ke bawah; kalau tak muat ke atas; lalu clamp agar menu
                            // selalu utuh di dalam container daftar pesan.
                            place() {
                                const box = document.getElementById(@js($containerId));
                                const menu = this.$refs.menu;
                                if (!box || !menu) return;

                                const area = box.getBoundingClientRect();
                                const btn = this.$refs.trigger.getBoundingClientRect();
                                const h = menu.offsetHeight;
                                const gap = 4, pad = 8;

                                let top = btn.bottom + gap;                 // buka ke bawah
                                if (top + h > area.bottom - pad) {
                                    top = btn.top - gap - h;                // buka ke atas
                                }
                                const maxTop = area.bottom - pad - h;
                                const minTop = area.top + pad;
                                top = Math.max(minTop, Math.min(top, maxTop));

                                // Menu absolute terhadap wrapper -> simpan relatif ke tombol.
                                this.topPx = top - btn.top;
                            },
                        }" @click.outside="open = false"
                        {{-- x-on: (bukan @) karena nama event-nya diinterpolasi Blade. --}}
                        x-on:{{ $scrollEvent }}.window="open = false"
                        class="relative shrink-0">
                        <button x-ref="trigger" @click="toggle()"
                            :class="open ? 'bg-gold text-background' : 'bg-white/10 text-foreground hover:bg-gold hover:text-background'"
                            class="{{ $t['trigger'] }} rounded-full border border-white/10 shadow-sm transition"
                            aria-label="{{ __('chat.message_actions') }}">
                            <svg class="{{ $t['triggerIcon'] }}" fill="currentColor" viewBox="0 0 20 20"><path d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z" /></svg>
                        </button>
                        <div x-show="open" x-cloak x-ref="menu"
                            :style="`top: ${topPx}px`"
                            :class="placed ? 'opacity-100' : 'opacity-0'"
                            class="absolute z-30 {{ $mine ? 'right-0' : 'left-0' }} {{ $t['menu'] }} py-1 bg-surface border border-white/10 rounded-xl shadow-lg overflow-hidden transition-opacity duration-150">
                            <button wire:click="startReply({{ $message->id }})" @click="open = false"
                                class="w-full flex items-center {{ $t['item'] }} text-left font-mono font-semibold text-foreground hover:bg-white/5 transition">
                                <svg class="{{ $t['itemIcon'] }} shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h10a8 8 0 018 8v2M3 10l6 6M3 10l6-6" /></svg>
                                {{ __('chat.reply') }}
                            </button>
                            @if ($canEdit)
                                <button wire:click="startEdit({{ $message->id }})" @click="open = false"
                                    class="w-full flex items-center {{ $t['item'] }} text-left font-mono font-semibold text-gold hover:bg-gold/10 transition">
                                    <svg class="{{ $t['itemIcon'] }} shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z" /></svg>
                                    {{ __('chat.edit') }}
                                </button>
                            @endif
                            <button wire:click="deleteForMe({{ $message->id }})" @click="open = false"
                                class="w-full flex items-center {{ $t['item'] }} text-left font-mono font-semibold text-amber-400 hover:bg-amber-400/10 transition">
                                <svg class="{{ $t['itemIcon'] }} shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.542-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.88 9.88l-3.29-3.29m7.532 7.532l3.29 3.29M3 3l3.59 3.59m0 0L21 21" /></svg>
                                {{ __('chat.delete_for_me') }}
                            </button>
                            @if ($canDeleteEveryone)
                                <button wire:click="deleteForEveryone({{ $message->id }})"
                                    wire:confirm="{{ __('chat.confirm_delete_everyone') }}" @click="open = false"
                                    class="w-full flex items-center {{ $t['item'] }} text-left font-mono font-semibold text-red-400 hover:bg-red-400/10 transition">
                                    <svg class="{{ $t['itemIcon'] }} shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6M9 7V4a1 1 0 011-1h4a1 1 0 011 1v3M4 7h16" /></svg>
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
