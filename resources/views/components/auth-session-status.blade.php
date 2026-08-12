{{-- Renders a session status message (e.g. after login or password reset), if present. --}}
@props(['status'])

@if ($status)
    <div {{ $attributes->merge(['class' => 'font-medium text-sm text-gold']) }}>
        {{ $status }}
    </div>
@endif
