@props(['active'])

@php
$classes = ($active ?? false)
            ? 'block w-full ps-3 pe-4 py-2 border-l-4 border-typing-accent text-start text-base font-medium text-typing-accent bg-typing-accent/10 focus:outline-none focus:text-typing-accent focus:bg-typing-accent/15 focus:border-typing-accent transition duration-150 ease-in-out'
            : 'block w-full ps-3 pe-4 py-2 border-l-4 border-transparent text-start text-base font-medium text-typing-muted hover:text-typing-text hover:bg-typing-surface hover:border-white/20 focus:outline-none focus:text-typing-text focus:bg-typing-surface focus:border-white/20 transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
