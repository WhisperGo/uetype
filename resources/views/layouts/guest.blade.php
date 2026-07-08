<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ $pageTitle ?? config('app.name', 'UEType') }}</title>
        @include('partials.favicon')

        <!-- Fonts: JetBrains Mono untuk semua teks readable; Pixelify Sans & Press Start 2P untuk aksen game -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,100..800;1,100..800&family=Pixelify+Sans:wght@400..700&family=Press+Start+2P&display=swap" rel="stylesheet">

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
                {{ $slot }}
            </div>
        </div>
    </body>
</html>
