{{-- Guest layout for unauthenticated pages (login, onboarding): centered card with logo. --}}
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $pageTitle ?? config('app.name', 'UeType') }}</title>
        @include('partials.favicon')

        <!-- Fonts: JetBrains Mono for all readable text; Pixelify Sans & Press Start 2P for game accents -->
        @include('layouts._fonts')

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-mono antialiased text-foreground">
        <div class="relative min-h-screen flex flex-col sm:justify-center items-center pt-6 sm:pt-0 bg-background overflow-hidden">
            <div class="pointer-events-none absolute inset-0 bg-[radial-gradient(60%_50%_at_50%_0%,rgba(var(--color-brand)/0.14),transparent_70%)]"></div>

            <div class="relative">
                <a href="{{ route('home') }}" class="flex items-center gap-3 group">
                    <x-application-logo class="h-12 w-auto transition-transform group-hover:scale-105" />
                    <span class="font-display text-lg text-gold leading-none pt-1">UETYPE</span>
                </a>
            </div>

            <div class="relative w-full sm:max-w-md mt-6 px-6 py-8 bg-surface/80 backdrop-blur border border-white/10 shadow-glow overflow-hidden sm:rounded-2xl">
                @if ($backUrl)
                    {{-- A plain <a>, deliberately: this layout never loads @livewireScripts, and
                         resources/js/app.js registers every Alpine component inside an
                         alpine:init listener that therefore never fires here. The history.back()
                         enhancement used on the profile arrow would be dead markup on this page,
                         so the href IS the whole control. Do not "unify" the two.

                         <x-header-link> rather than <x-icon-button>: a link WITH TEXT is the
                         32px / WCAG 2.5.8 case, not the 44px icon-only one, and it already
                         ships a focus-visible ring. --}}
                    <div class="mb-6">
                        <x-header-link back href="{{ $backUrl }}">{{ __('auth.back') }}</x-header-link>
                    </div>
                @endif

                {{ $slot }}
            </div>
        </div>
    </body>
</html>
