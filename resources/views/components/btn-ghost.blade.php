@props([
    'size' => 'md',
    'as' => 'button',
])

@php
    // Secondary button: the second most duplicated pattern after btn-gold (14 usages).
    $base = 'font-mono text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition';

    $sizes = [
        'xs' => 'px-2.5 py-1 text-[0.7rem]',
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-1.5 text-xs',
        'wide' => 'px-4 py-2 text-xs',
        'lg' => 'px-5 py-2 text-xs',
    ];

    // Values outside the map are used as-is (see btn-gold).
    $classes = $base.' '.($sizes[$size] ?? $size);
@endphp

@if ($as === 'a')
    <a {{ $attributes->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
