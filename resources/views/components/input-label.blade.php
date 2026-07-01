@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-sans font-medium text-sm text-muted']) }}>
    {{ $value ?? $slot }}
</label>
