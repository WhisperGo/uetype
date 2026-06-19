@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-3 pt-1 text-sm font-semibold leading-5 text-typing-accent focus:outline-none transition duration-150 ease-in-out'
            : 'inline-flex items-center px-3 pt-1 text-sm font-medium leading-5 text-typing-muted hover:text-typing-text focus:outline-none transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
