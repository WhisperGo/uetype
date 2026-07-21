@props([
    'title' => null,
    'body' => null,
    'spacing' => '24',
    'card' => false,
])

{{-- Uniform empty state: faded mascot + title + caption + optional CTA.

     This block was previously copied in 8 places and had ALREADY drifted into four
     variants (py-16/py-24, w-16/w-16 h-16) -- the classic sign of duplication
     getting out of hand. The mascot is locked to `w-16 h-16` (not just `w-16`) so
     its height is reserved and the layout doesn't shift while the image loads slowly.

     The CTA is intentionally a slot, not a prop: its variants are too diverse --
     some have no button, one is a wire:navigate link, one a wire:click button, and
     some have three mixed buttons. --}}
<div @class([
    'flex flex-col items-center justify-center text-center select-none',
    'py-16' => $spacing === '16',
    'py-24' => $spacing !== '16',
    'border border-white/5 rounded-2xl bg-surface/20' => $card,
])>
    <img src="/icon/uetype_mascot.png" alt="" class="w-16 h-16 opacity-30 mb-4">

    @if ($title)
        <p class="font-mono text-sm font-bold text-foreground">{{ $title }}</p>
    @endif

    @if ($body)
        <p @class(['font-mono text-xs text-muted', 'mt-1' => $title])>{{ $body }}</p>
    @endif

    {{-- The default slot is used when the caption isn't a simple string
         (e.g. a ternary expression in clan search results). --}}
    @if (trim($slot) !== '')
        <div @class(['font-mono text-xs text-muted', 'mt-1' => $title])>{{ $slot }}</div>
    @endif

    @isset($cta)
        <div class="flex flex-wrap items-center justify-center gap-3 mt-5">{{ $cta }}</div>
    @endisset
</div>
