@props([
    'user',
    'online' => null,
    'size' => 'w-10 h-10',
    'ring' => 'border-white/10',
    'fallbackSize' => 'w-7 h-7',
    'shape' => 'rounded-lg',
    'bg' => 'bg-white/5',
    'bordered' => true,
])

{{-- Avatar pengguna dengan fallback maskot. SATU-SATUNYA tempat avatar dirender;
     jangan salin markupnya.

     Ini bukan aturan kosmetik: dua atribut `alt` yang sempat hilang dan harus
     diperbaiki manual berada tepat di tempat yang menulis markup avatar sendiri
     alih-alih memakai komponen ini. Duplikasi di sini memproduksi bug.

     Semua nilai default menjaga 15 pemakaian lama tetap identik; props
     shape/bg/bordered ditambahkan supaya varian di lobby multiplayer
     (rounded-md tanpa border, rounded-xl bg-foreground/5) ikut tertampung. --}}
<div class="relative shrink-0">
    <div {{ $attributes->class([
        'overflow-hidden flex items-center justify-center',
        $shape,
        $bg,
        $size,
        'border '.$ring => $bordered,
    ]) }}>
        @if ($user->avatar)
            <img src="{{ $user->avatar }}" alt="{{ $user->username }}" referrerpolicy="no-referrer"
                class="w-full h-full object-cover">
        @else
            <img src="/icon/uetype_mascot.png" alt="{{ $user->username }}" class="{{ $fallbackSize }} object-contain">
        @endif
    </div>

    @if (! is_null($online))
        <span @class([
            'absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-surface',
            'bg-active' => $online,
            'bg-white/25' => ! $online,
        ]) title="{{ $online ? __('friends.online') : __('friends.offline') }}"></span>
    @endif
</div>
