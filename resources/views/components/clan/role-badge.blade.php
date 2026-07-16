@props(['role'])

@php
    $value = is_object($role) ? ($role->value ?? null) : $role;
@endphp

@if ($value === 'leader')
    <span {{ $attributes->merge(['class' => 'px-3 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg shrink-0 inline-flex items-center gap-1.5']) }}>
        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M4 8l3.5 3L12 5l4.5 6L20 8l-1.5 10h-13L4 8z" /></svg>
        {{ __('clan.role.leader') }}
    </span>
@endif
