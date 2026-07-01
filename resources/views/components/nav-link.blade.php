@props(['active'])

@php
$classes = ($active ?? false)
            ? 'inline-flex items-center px-3 pt-1 text-sm font-semibold leading-5 text-brand focus:outline-none transition duration-150 ease-in-out'
            : 'inline-flex items-center px-3 pt-1 text-sm font-medium leading-5 text-muted hover:text-foreground focus:outline-none transition duration-150 ease-in-out';
@endphp

<a {{ $attributes->merge(['class' => $classes]) }}>
    {{ $slot }}
</a>
