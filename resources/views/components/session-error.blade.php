@props(['message' => null])

{{--
    A flashed `error` is the only channel a redirect has to explain itself.

    It was rendered in exactly ONE place (the multiplayer lobby), which is why the login
    page's own failure message -- flashed by GoogleAuthController::callback() since the
    controller was written -- had never once reached a user: a failed Google sign-in bounced
    them back to a blank card with no hint at all.

    Markup lifted verbatim from that lobby block so its appearance is unchanged.
--}}
@if ($message)
    <p {{ $attributes->class('flex items-center justify-center gap-1.5 font-mono text-xs text-danger') }}>
        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
        </svg>
        <span>{{ $message }}</span>
    </p>
@endif
