@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-typing-success']) }}>
        {{ $status }}
    </div>
@endif
