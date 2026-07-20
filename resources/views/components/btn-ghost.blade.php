@props([
    'size' => 'md',
    'as' => 'button',
])

@php
    // Tombol sekunder: duplikasi terbesar kedua setelah btn-gold (14 pemakaian).
    $base = 'font-mono text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition';

    $sizes = [
        'xs' => 'px-2.5 py-1 text-[0.7rem]',
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-1.5 text-xs',
        'wide' => 'px-4 py-2 text-xs',
        'lg' => 'px-5 py-2 text-xs',
    ];

    // Nilai di luar peta dipakai apa adanya (lihat btn-gold).
    $classes = $base.' '.($sizes[$size] ?? $size);
@endphp

@if ($as === 'a')
    <a {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
