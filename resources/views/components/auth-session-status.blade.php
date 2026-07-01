@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-gold']) }}>
        {{ $status }}
    </div>
@endif
