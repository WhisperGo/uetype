@props([
    'size' => 'md',
    'as' => 'button',
])

@php
    // Invariant across 22 of 23 previous usages; only padding and text size
    // varied, so those are the only props.
    $base = 'font-mono font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition';

    $sizes = [
        'xs' => 'px-2.5 py-1 text-[0.7rem]',
        'sm' => 'px-3 py-1.5 text-xs',
        'md' => 'px-4 py-1.5 text-xs',
        'wide' => 'px-4 py-2 text-xs',
        'lg' => 'px-5 py-2 text-xs',
        'xl' => 'px-5 py-2.5 text-xs',
    ];

    // Values outside the map are used as-is. Some buttons have one-off padding;
    // handling that via `class=` would clash with the map's padding (two identical
    // Tailwind utilities, unpredictable win order).
    $classes = $base.' '.($sizes[$size] ?? $size);
@endphp

{{-- Primary action button (gold). `as` accommodates use as <a> for navigation
     and <span> for non-interactive badges -- both already exist in the codebase,
     so forcing <button> would change the HTML semantics. --}}
@if ($as === 'a')
    <a {{ $attributes->class($classes) }}>{{ $slot }}</a>
@elseif ($as === 'span')
    <span {{ $attributes->class($classes) }}>{{ $slot }}</span>
@else
    <button {{ $attributes->merge(['type' => 'button'])->class($classes) }}>{{ $slot }}</button>
@endif
