@php
    $isRegister = ($mode ?? 'login') === 'register';
@endphp

<div class="flex flex-col items-center text-center">
    <img src="{{ asset('icon/uetype_mascot.png') }}" alt="Maskot UeType"
        class="h-20 w-20 object-contain drop-shadow-[0_0_18px_rgba(var(--color-brand)/0.35)]">

    <p class="mt-4 font-mono text-[0.7rem] uppercase tracking-[0.35em] text-gold">
        {{ $isRegister ? __('auth.create_account') : __('auth.welcome') }}
    </p>

    <h1 class="mt-2 font-display text-h6 leading-tight text-foreground">
        {{ __('auth.headline_start') }} <span class="text-brand">{{ __('auth.headline_accent') }}</span>
    </h1>

    <p class="mt-3 max-w-xs font-mono text-sm leading-6 text-muted">
        {{ __('auth.tagline') }}
    </p>
</div>

<div class="mt-8">
    <a href="{{ route('auth.google') }}"
        class="group flex w-full items-center justify-center gap-3 rounded-xl border border-white/10 bg-surface px-4 py-3.5 font-sans text-sm font-semibold text-foreground transition duration-200 hover:border-white/20 hover:bg-white/5 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-background">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-5 w-5 shrink-0" viewBox="0 0 24 24">
            <path fill="#4285F4"
                d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.53-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-8.17z" />
            <path fill="#34A853"
                d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.11 0-5.74-2.11-6.68-4.96H1.32v3.15C3.31 20.36 7.38 24 12 24z" />
            <path fill="#FBBC05"
                d="M5.32 14.24A7.16 7.16 0 0 1 5 12c0-.79.13-1.57.32-2.34V6.51H1.32A11.94 11.94 0 0 0 0 12c0 1.92.45 3.74 1.32 5.39l4-3.15z" />
            <path fill="#EA4335"
                d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.38 0 3.31 3.64 1.32 7.51l4 3.15c.94-2.85 3.57-4.91 6.68-4.91z" />
        </svg>
        {{ __('auth.continue_google') }}
    </a>
</div>

<p class="mt-6 text-center text-x-small leading-5 text-muted">
    {{ __('auth.privacy_prefix') }}
    <a href="{{ route('terms') }}" class="text-muted underline decoration-white/20 underline-offset-2 transition-colors hover:text-foreground">
        {{ __('auth.privacy_link') }}
    </a>
    {{ __('auth.privacy_suffix') }}
</p>
