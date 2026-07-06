@props(['width' => 'max-w-5xl'])

<div {{ $attributes->merge(['class' => $width.' mx-auto w-full px-4 sm:px-6 lg:px-8']) }}>
    {{ $slot }}
</div>
