{{--
    Panel chat lobby room multiplayer. Broadcast-only: pesan TIDAK disimpan di
    database — hanya disiarkan lewat channel 'room.{code}' (event RoomMessageSent)
    dan ditahan di state Alpine sisi klien. Jadi player yang baru join tak melihat
    riwayat sebelumnya; itu memang perilaku yang diinginkan untuk obrolan sesaat.

    Dipakai di dua tempat (blok 'waiting' & modal hasil), maka dibuat partial:
    - $currentUserId : id user aktif, untuk membedakan bubble sendiri vs orang lain.

    Alur:
    - Kirim  -> optimistic append lokal + $wire.sendRoomMessage(body) [broadcast ->toOthers]
    - Terima -> window 'room-message-received' (di-relay race-echo.js) -> append.
--}}
<div
    x-data="roomChat({
        me: {{ $currentUserId }},
        youLabel: @js(__('multiplayer.chat_you')),
        joinLabel: @js(__('multiplayer.chat_joined', ['name' => ':name'])),
        leaveLabel: @js(__('multiplayer.chat_left', ['name' => ':name'])),
    })"
    class="border bg-surface/40 border-border/40 rounded-2xl overflow-hidden flex flex-col"
    style="max-height: 22rem;">

    <div class="px-4 py-3 border-b border-border/40 flex items-center gap-2 shrink-0">
        <span class="text-sm leading-none">&#128172;</span>
        <h3 class="text-xs uppercase tracking-widest text-muted font-mono font-bold">
            {{ __('multiplayer.chat_title') }}
        </h3>
    </div>

    {{-- Daftar pesan. x-ref="log" untuk auto-scroll ke bawah saat ada pesan baru. --}}
    <div x-ref="log" class="flex-1 overflow-y-auto chat-scroll px-4 py-3 space-y-2.5 min-h-[8rem]">
        <template x-if="messages.length === 0">
            <p class="text-xs font-mono text-muted/70 text-center py-6">
                {{ __('multiplayer.chat_empty') }}
            </p>
        </template>

        {{-- Bubble & warna disamakan dengan halaman chat (components/chat/message):
             pesan sendiri = emas (rounded-br-md), pesan orang lain = putih transparan
             (rounded-bl-md) dengan nama pengirim di atasnya.
             Pesan sistem (join/leave) tampil di tengah, warna tipis (bukan bubble). --}}
        <template x-for="(msg, i) in messages" :key="i">
            <div>
                {{-- Notif kehadiran: pil samar di tengah, tak menonjol. --}}
                <template x-if="msg.system">
                    <div class="flex justify-center">
                        <span class="px-3 py-1 rounded-full bg-white/[0.03] font-mono text-[0.7rem] text-muted/60"
                            x-text="msg.body"></span>
                    </div>
                </template>

                {{-- Pesan biasa. --}}
                <template x-if="!msg.system">
                    <div class="flex" :class="msg.mine ? 'justify-end' : 'justify-start'">
                        <div class="max-w-[75%]" :class="msg.mine ? '' : 'flex flex-col items-start'">
                            <p x-show="!msg.mine" class="font-mono text-[0.65rem] mb-1 text-muted px-1" x-text="msg.username"></p>
                            <div class="px-4 py-2.5 rounded-2xl text-sm font-mono break-words"
                                :class="msg.mine ? 'bg-gold text-background rounded-br-md' : 'bg-white/5 text-foreground rounded-bl-md'"
                                x-text="msg.body"></div>
                        </div>
                    </div>
                </template>
            </div>
        </template>
    </div>

    {{-- Form kirim. Input & tombol disamakan dengan halaman chat (components/chat/composer):
         input bg-surface/40 border-white/10 focus:border-gold, tombol send emas (btn-gold). --}}
    <form x-on:submit.prevent="send()" class="flex items-center gap-3 p-4 border-t border-white/5 shrink-0">
        <input type="text" x-model="draft" maxlength="500" autocomplete="off"
            placeholder="{{ __('multiplayer.chat_placeholder') }}"
            class="flex-1 px-4 py-2.5 rounded-2xl text-sm bg-surface/40 border border-white/10 font-mono text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
        <x-btn-gold type="submit" size="xl" class="disabled:opacity-40" x-bind:disabled="draft.trim() === ''">
            {{ __('multiplayer.chat_send') }}
        </x-btn-gold>
    </form>
</div>
