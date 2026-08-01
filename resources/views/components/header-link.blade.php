@props([
    'back' => false,
    'label' => null,
])

@php
    // Secondary link in a page header ("Leaderboard", "Back to Clan").
    //
    // Five hand-written variants existed across clan-war, clan-leaderboard, clan-show,
    // achievements and profile: four font sizes, three chevron sizes, one aria-label between
    // all of them, and no focus ring anywhere. The Clan War pair had no padding at all -- a
    // click box as tall as its 12px text, half of WCAG 2.5.8's 24px minimum.
    //
    // 32px box, matching <x-nav-link> and for the same reason: a link with TEXT is not the
    // icon-only case <x-icon-button> covers at 44px (2.5.5), and 32px clears 2.5.8 with room.
    //
    // The negative margins are what make the box growable at all: `-my-2.5` cancels `py-2.5`
    // and `-mx-2` cancels `px-2`, so the target expands over its neighbours' whitespace
    // without moving the header row -- the binding rule in docs/design-system.md. With the
    // header's own `gap-4` the two boxes each grow 8px sideways and meet exactly in the
    // middle: touching, never overlapping.
    $classes = 'inline-flex items-center gap-1.5 rounded font-mono text-xs text-muted '
        .'px-2 py-2.5 -mx-2 -my-2.5 '
        .'hover:text-foreground focus:outline-none focus-visible:ring-1 focus-visible:ring-border transition';

    // Optional, unlike <x-icon-button>'s required `label`: the slot text already supplies the
    // accessible name here. This exists for the case where the visible text names a
    // DESTINATION rather than the action ("Leaderboard" -> "Back to the leaderboard").
    $aria = $label !== null ? ['aria-label' => $label] : [];
@endphp

{{-- ALWAYS give this a text slot. An icon-only back control is <x-icon-button> (44px + a
     required label): an empty header-link would be a glyph whose click box is the size of the
     glyph, which is exactly what TouchTargetTest exists to catch. --}}
<a {{ $attributes->merge($aria)->class($classes) }}>
    @if ($back)
        {{-- aria-hidden: the chevron only repeats what the text already says. --}}
        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor"
            stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
        </svg>
    @endif
    <span>{{ $slot }}</span>
</a>
