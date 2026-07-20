@props([
    'size' => 'lg',
    // Fungsi kirim global milik varian ini. Sengaja terpisah per varian: di /chat
    // kedua komponen hidup bersamaan, dan satu fungsi bersama akan menulis bubble
    // optimistic ke container yang salah.
    'sendFn' => 'window.chatSend',
    // x-ref input; harus unik antar varian agar $refs tak bertabrakan.
    'inputRef' => 'msgInput',
])

@php
    $t = $size === 'sm'
        ? ['form' => 'gap-2 p-3', 'input' => 'px-3 py-2 rounded-xl text-xs', 'btn' => 'px-3 py-2 text-[0.7rem]']
        : ['form' => 'gap-3 p-4', 'input' => 'px-4 py-2.5 rounded-2xl text-sm', 'btn' => 'xl'];
@endphp

{{-- Kirim lewat fetch() ke /chat/send (paralel, di luar antrean Livewire) agar
     spam pesan tak saling menunggu. --}}
<form x-data="{ draft: '' }"
    @submit.prevent="
        const b = draft.trim();
        if (b === '') return;
        {{ $sendFn }}(b);
        draft = '';
        $refs.{{ $inputRef }}.focus();
    "
    class="flex items-center {{ $t['form'] }} border-t border-white/5 shrink-0">
    <input type="text" x-model="draft" x-ref="{{ $inputRef }}" maxlength="2000" autocomplete="off"
        placeholder="{{ __('chat.placeholder') }}"
        class="flex-1 {{ $t['input'] }} bg-surface/40 border border-white/10 font-mono text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
    <x-btn-gold type="submit" :size="$t['btn']" class="disabled:opacity-40" x-bind:disabled="draft.trim() === ''">
        {{ __('chat.send') }}
    </x-btn-gold>
</form>
