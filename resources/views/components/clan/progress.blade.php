@props(['data', 'showUnit' => false])

@php
    $percent = ($data['needed'] ?? 0) > 0
        ? min(100, round($data['progress'] / $data['needed'] * 100))
        : 0;
@endphp

<div {{ $attributes->merge(['class' => 'relative']) }}>
    <div class="flex justify-between font-mono text-[0.6rem] uppercase tracking-wider text-muted mb-1.5">
        <span>{{ __('clan.level', ['level' => $data['level']]) }}</span>
        <span>{{ $data['progress'] }} / {{ $data['needed'] }}@if ($showUnit) {{ __('clan.power') }} @endif</span>
        <span>{{ __('clan.level', ['level' => $data['next_level']]) }}</span>
    </div>
    <div class="h-2 w-full rounded-full bg-white/5 overflow-hidden">
        <div class="h-full rounded-full bg-gold transition-all" style="width: {{ $percent }}%"></div>
    </div>
</div>
