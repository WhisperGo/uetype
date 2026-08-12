{{-- Role badge for a roster row. Nothing for a plain member -- a badge on every row would
     stop the two that carry authority from standing out. Gold marks the single leader;
     co-leader stays quieter so two gold badges do not compete. --}}
@props(['role'])

@php
    $value = is_object($role) ? ($role->value ?? null) : $role;

    $chip = 'px-3 py-1 font-mono text-xs font-bold rounded-lg shrink-0 inline-flex items-center gap-1.5 border';
@endphp

@if ($value === 'leader')
    <span {{ $attributes->merge(['class' => $chip.' text-gold border-gold/40']) }}>
        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M4 8l3.5 3L12 5l4.5 6L20 8l-1.5 10h-13L4 8z" /></svg>
        {{ __('clan.role.leader') }}
    </span>
@elseif ($value === 'co-leader')
    <span {{ $attributes->merge(['class' => $chip.' text-foreground/80 border-white/15']) }}>
        <svg class="w-3 h-3" fill="none" stroke="currentColor" stroke-width="2" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 8.7l5.4-.8z" /></svg>
        {{ __('clan.role.co-leader') }}
    </span>
@endif
