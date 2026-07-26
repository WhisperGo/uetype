{{-- XP earned + level progress, shown on a result screen.

     Extracted because this panel existed THREE times: twice verbatim in the solo result
     screen (once per layout branch) and once more in the multiplayer result panel, where the
     copy had drifted in a dozen details -- a 1.5px bar instead of 2px, a bordered track, a
     gold `font-black` figure instead of `font-bold` foreground, a 10px label, and
     "Level 1 - 2" written with a hyphen while solo used the arrow from its own lang string.
     The same information looked different depending on which mode you had just played.

     The solo styling is the one kept: its 2px `bg-white/5` track already matches
     <x-clan.progress>, so the multiplayer variant was the lone outlier of three bars.

     Lang keys stay in `result.*` -- both callers are result screens, and `multiplayer.*` only
     ever held a partial set (no "+:amount XP", no arrow), which is how the hyphen crept in.

     $level is a User::levelData() array: progress, needed, level, next_level. --}}
@props(['earned', 'level'])

@php
    $percent = ($level['needed'] ?? 0) > 0
        ? min(100, ($level['progress'] / $level['needed']) * 100)
        : 0;
@endphp

<div {{ $attributes->merge(['class' => 'bg-surface/70 border border-white/5 rounded-2xl p-4']) }}>
    <div class="flex items-start justify-between mb-3">
        <div class="flex flex-col gap-2">
            <span class="font-mono text-xs uppercase tracking-[0.2em] text-muted">{{ __('result.xp_earned') }}</span>
            <span class="text-xl sm:text-2xl font-bold font-mono text-foreground leading-none">{{ __('result.xp_gained', ['amount' => $earned]) }}</span>
        </div>
        <div class="text-right font-mono text-xs text-muted leading-relaxed">
            <div>{{ __('result.xp_progress', ['progress' => number_format($level['progress']), 'needed' => number_format($level['needed'])]) }}</div>
            <div>{{ __('result.xp_level_up', ['from' => $level['level'], 'to' => $level['next_level']]) }}</div>
        </div>
    </div>
    <div class="h-2 overflow-hidden rounded-full bg-white/5">
        <div class="h-full rounded-full bg-foreground transition-all" style="width: {{ $percent }}%"></div>
    </div>
</div>
