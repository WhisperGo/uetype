@props(['value'])

<label {{ $attributes->merge(['class' => 'block font-mono font-medium text-sm text-muted']) }}>
    {{ $value ?? $slot }}
</label>
