{{-- Mobile/responsive navigation link with active/inactive styling. --}}
@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-brand-bright text-start text-base font-medium text-brand-bright bg-brand-bright/10 focus:outline-none focus:text-brand-bright focus:bg-brand-bright/15 focus:border-brand-bright transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-muted hover:text-foreground hover:bg-surface hover:border-white/20 focus:outline-none focus:text-foreground focus:bg-surface focus:border-white/20 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
