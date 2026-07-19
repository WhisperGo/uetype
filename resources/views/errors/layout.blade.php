{{--
    Shared layout for HTTP error pages (404/403/500/503). Deliberately
    self-contained (no navigation/auth/WebSocket like layouts.app) so it renders
    even when the app is broken or the user is unauthenticated. Mirrors the guest
    layout's look: radial-gradient background, logo, gold brand, surface card.

    Child pages set: $code, $title, $message. Optional $home controls whether the
    "back to home" button is shown (default true).
--}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">

    <title>{{ ($code ?? __('errors.error')) . ' · ' . config('app.name', 'UeType') }}</title>
    @include('partials.favicon')

    @include('layouts._fonts')

    @vite(['resources/css/app.css'])
</head>

<body class="font-mono antialiased text-foreground bg-background selection:bg-brand selection:text-foreground">
    <div class="relative min-h-screen flex flex-col justify-center items-center px-6 py-12 bg-background overflow-hidden">
        {{-- Ambient glow, sama seperti layout guest. --}}
        <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_50%_at_50%_0%,rgba(var(--color-brand)/0.14),transparent_70%)]"></div>

        <div class="relative w-full max-w-md text-center">
            {{-- Brand --}}
            <a href="{{ route('home') }}" class="inline-flex items-center gap-3 group mb-10">
                <x-application-logo class="h-10 w-auto transition-transform group-hover:scale-105" />
                <span class="font-display text-base text-gold leading-none pt-1">UETYPE</span>
            </a>

            {{-- Kode error besar, gaya pixel/gold. --}}
            <p class="font-display text-gold leading-none tracking-tight text-[clamp(3.5rem,2rem+10vw,6rem)]">
                {{ $code ?? '???' }}
            </p>

            <div class="mt-6 rounded-2xl border border-white/10 bg-surface/80 backdrop-blur shadow-glow px-6 py-7">
                <h1 class="text-fluid-title font-bold text-foreground uppercase tracking-wider">
                    {{ $title }}
                </h1>
                <p class="mt-3 text-small text-muted leading-relaxed">
                    {{ $message }}
                </p>

                @if ($home ?? true)
                    <a href="{{ route('home') }}"
                        class="mt-6 inline-flex items-center gap-2 rounded-xl border border-brand bg-brand/15 px-5 py-2.5 text-small text-brand-bright hover:bg-brand/25 transition-colors">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M3 12l9-9 9 9M5 10v10a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1V10" />
                        </svg>
                        {{ __('errors.back_home') }}
                    </a>
                @endif
            </div>
        </div>
    </div>
</body>

</html>
