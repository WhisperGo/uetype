{{-- Desktop top-bar navigation link with active/inactive styling.

     Padding is deliberately tight and SYMMETRIC. The old `px-3 pt-1` came from Breeze,
     where the link carried a `border-b-2` that had to sit flush with the nav's own bottom
     border; that border is long gone (active state is colour + weight now), so the lone
     `pt-1` only pushed the label off-centre while the wide `px-3` made the hit box far
     larger than the word. Paired with `sm:items-center` on the container in
     layouts/navigation.blade.php -- without that, the link stretches to the full 64px bar
     and none of this matters. Result: a 32px hit box, still clear of the 24px WCAG 2.5.8
     minimum target size. --}}
@props(['active'])

@php
$base = 'inline-flex items-center rounded px-2 py-1.5 text-sm leading-5 focus:outline-none focus-visible:ring-1 focus-visible:ring-border transition duration-150 ease-in-out';

// `focus:outline-none` alone would leave keyboard users with no focus indicator at all,
// so it's paired with a focus-visible ring -- same pattern as the account dropdown
// trigger. It never shows for mouse clicks, so the everyday look is unchanged.
$classes = ($active ?? false)
            ? $base.' font-semibold text-brand-bright'
            : $base.' font-medium text-muted hover:text-foreground';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
