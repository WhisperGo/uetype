{{-- Clan emblem badge: renders the clan's colored icon, or its initials as a fallback.

     `clan` may be null: a finished war outlives a disbanded clan (see the 2026_08_03
     migration), so the history rows that render this can hold a war whose other side no
     longer exists. Nullsafe throughout, which lands on the neutral default colour and a
     '?' initial -- the same shape as a clan with no emblem set. --}}
@props(['clan' => null, 'size' => 'md'])

@php
    use App\Support\ClanEmblem;

    $hasEmblem = ClanEmblem::isValidIcon($clan?->emblem);
    $hex = ClanEmblem::colorHex($clan?->emblem_color);
    $iconPath = ClanEmblem::iconPath($clan?->emblem);

    $box = match ($size) {
        'sm' => 'w-10 h-10 rounded-lg',
        'lg' => 'w-20 h-20 rounded-2xl',
        default => 'w-14 h-14 rounded-xl',
    };
    $icon = match ($size) {
        'sm' => 'w-5 h-5',
        'lg' => 'w-10 h-10',
        default => 'w-7 h-7',
    };
    $initial = match ($size) {
        'sm' => 'text-base',
        'lg' => 'text-3xl',
        default => 'text-xl',
    };

    $letters = mb_strtoupper(mb_substr($clan?->name ?? '?', 0, 2));
@endphp

<div {{ $attributes->merge(['class' => $box.' shrink-0 flex items-center justify-center border']) }}
    style="background-color: {{ $hex }}1a; border-color: {{ $hex }}59; color: {{ $hex }};">
    @if ($hasEmblem)
        <svg class="{{ $icon }}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75"
            stroke-linecap="round" stroke-linejoin="round">
            <path d="{{ $iconPath }}" />
        </svg>
    @else
        <span class="{{ $initial }} font-bold tracking-tight font-mono">{{ $letters }}</span>
    @endif
</div>
