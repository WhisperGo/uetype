@props([
    'tone' => 'default',
    'label',
    'as' => 'button',
])

@php
    // min-w/min-h, NOT w/h: the GLYPH stays small and only the CLICK BOX grows, so a slot
    // larger than 20px must still be able to push the box out rather than get clipped.
    // 44px is WCAG 2.5.5 (AA). Centring is left to flex so no padding value has to be
    // maintained per call site.
    $base = 'inline-flex items-center justify-center shrink-0 min-w-[44px] min-h-[44px] '
        .'rounded-lg transition focus:outline-none focus-visible:ring-1';

    // Only the two tones that actually have callers today. `danger` is a separate tone
    // rather than a `class=` override so a destructive action cannot quietly ship wearing
    // the neutral colour -- which is what "clear chat" did while sitting next to "close".
    $tones = [
        'default' => 'text-muted hover:text-foreground hover:bg-surface focus-visible:ring-border',
        'danger' => 'text-muted hover:text-danger hover:bg-danger/10 focus-visible:ring-danger/50',
    ];

    $classes = $base.' '.($tones[$tone] ?? $tones['default']);
@endphp

{{-- Icon-only control with a touch target that meets WCAG 2.5.5 (44x44px).

     Before this component there were ~18 icon buttons each writing their own `p-1` +
     `w-4 h-4` (= 24x24px), and two with NO padding at all (= 16x16px). Two of the smallest
     were destructive and sat `gap-2` from a close button, so a thumb aiming to dismiss the
     chat drawer could wipe its history instead (chat-overlay.blade.php).

     A shared component rather than a `.tap-44` utility class: the size was never the whole
     defect. Every call site also hand-wrote its colours and its `aria-label`, and a missing
     accessible name is a defect this project has already shipped -- the two `<img>` tags
     found without `alt` were both in markup written by hand instead of through a component
     (see SharedComponentsTest). A utility can only be applied by whoever remembers it; a
     component supplies the default and, via the required `label` prop, refuses to render
     without a name.

     The size is deliberately NOT wrapped in @media (pointer: coarse), unlike `.touch-only`
     in app.css. That query is right for `.touch-only` because it decides whether an element
     EXISTS -- a "tap to type" hint is nonsense on a desktop. A 44px target is never wrong
     anywhere: WCAG 2.5.5 is not a touch-only criterion, a mouse used with a tremor benefits,
     and hybrid devices (iPad with a trackpad) report `fine` while being used with a finger.
     The deciding reason is testability: this project executes no CSS in tests, so a media
     query branch would pass unguarded, and manual verification would have to be done twice
     per button.

     `label` has no default on purpose. This control has no text; without a name a screen
     reader announces only "button". --}}
@if ($as === 'a')
    <a {{ $attributes->merge(['aria-label' => $label, 'title' => $label])->class($classes) }}>{{ $slot }}</a>
@else
    <button {{ $attributes->merge(['type' => 'button', 'aria-label' => $label, 'title' => $label])->class($classes) }}>{{ $slot }}</button>
@endif
