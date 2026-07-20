@props([
    'size' => 'md',
    'as' => 'button',
])

@php
    // Invarian di 22 dari 23 pemakaian sebelumnya; yang bervariasi cuma padding
    // dan ukuran teks, jadi itu saja yang jadi prop.
    $base = 'font-mono font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition';

    $sizes = [
        'xs' => 'px-2.5 py-1 text-[0.7rem]',
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-1.5 text-xs',
        'wide' => 'px-4 py-2 text-xs',
        'lg' => 'px-5 py-2 text-xs',
        'xl' => 'px-5 py-2.5 text-xs',
    ];

    // Nilai di luar peta dipakai apa adanya. Beberapa tombol punya padding
    // one-off; menampungnya lewat `class=` akan bentrok dengan padding dari
    // peta (dua utility Tailwind yang sama, urutan menang tak terduga).
    $classes = $base.' '.($sizes[$size] ?? $size);
@endphp

{{-- Tombol aksi utama (emas). `as` mengakomodasi pemakaian sebagai <a> untuk
     navigasi dan <span> untuk badge non-interaktif -- keduanya sudah ada di
     codebase, jadi memaksakan <button> akan mengubah semantik HTML. --}}
@if ($as === 'a')
    <a {{ $attributes->class($classes) }}>{{ $slot }}</a>
@elseif ($as === 'span')
    <span {{ $attributes->class($classes) }}>{{ $slot }}</span>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
