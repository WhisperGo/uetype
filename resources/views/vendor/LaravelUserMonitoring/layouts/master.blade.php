<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>@yield('title') · UeType Monitoring</title>
        @includeIf('partials.favicon')

        {{-- Pakai design system UeType (token semantic + font), bukan Tailwind CDN. --}}
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link
            href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,100..800;1,100..800&family=Pixelify+Sans:wght@400..700&family=Press+Start+2P&display=swap"
            rel="stylesheet">
        @vite(['resources/css/app.css'])

        @yield('style')

        {{-- Auto-refresh dashboard (reload halaman berkala). Log dicatat realtime di DB;
             ini menyegarkan TAMPILAN. Bisa di-toggle & state disimpan di localStorage. --}}
        <script>
            (function () {
                const KEY = 'umAutoRefresh';
                const SECONDS = 10;
                let timer = null;
                function isOn() { return (localStorage.getItem(KEY) ?? 'on') === 'on'; }
                function start() { stop(); timer = setTimeout(() => window.location.reload(), SECONDS * 1000); }
                function stop() { if (timer) { clearTimeout(timer); timer = null; } }
                function apply() {
                    isOn() ? start() : stop();
                    const box = document.getElementById('um-autorefresh-toggle');
                    if (box) box.checked = isOn();
                }
                document.addEventListener('visibilitychange', () => {
                    document.hidden ? stop() : (isOn() && start());
                });
                window.umToggleAutoRefresh = function (checked) {
                    localStorage.setItem(KEY, checked ? 'on' : 'off');
                    apply();
                };
                document.addEventListener('DOMContentLoaded', apply);
            })();
        </script>
    </head>
    <body class="font-mono antialiased text-foreground bg-background selection:bg-brand selection:text-foreground min-h-screen">
        <div class="max-w-7xl mx-auto px-4 sm:px-6 lg:px-8 py-8">

            {{-- Header --}}
            <div class="flex items-center justify-between mb-6">
                <div class="flex items-center gap-3">
                    <a href="{{ url('/') }}" wire:navigate
                       class="text-x-small font-mono uppercase tracking-widest text-muted hover:text-foreground transition">
                        ← UeType
                    </a>
                    <span class="text-muted/40">/</span>
                    <h1 class="font-display text-sm text-gold">MONITORING</h1>
                </div>
                <a href="https://github.com/binafy/laravel-user-monitoring"
                   class="inline-flex items-center gap-2 px-4 py-2 rounded-lg bg-surface border border-border text-muted hover:text-foreground hover:border-elevated transition text-x-small font-mono">
                    <svg class="w-4 h-4" viewBox="0 0 24 24" fill="currentColor"><path d="M12 .5C5.73.5.5 5.73.5 12c0 5.08 3.29 9.39 7.86 10.91.58.11.79-.25.79-.56v-2c-3.2.7-3.88-1.54-3.88-1.54-.53-1.34-1.29-1.7-1.29-1.7-1.05-.72.08-.7.08-.7 1.16.08 1.77 1.19 1.77 1.19 1.03 1.77 2.71 1.26 3.37.96.1-.75.4-1.26.73-1.55-2.55-.29-5.24-1.28-5.24-5.68 0-1.25.45-2.28 1.19-3.08-.12-.29-.52-1.46.11-3.05 0 0 .97-.31 3.18 1.18a11 11 0 0 1 5.79 0c2.2-1.49 3.17-1.18 3.17-1.18.63 1.59.23 2.76.11 3.05.74.8 1.19 1.83 1.19 3.08 0 4.41-2.69 5.38-5.25 5.67.41.35.78 1.05.78 2.12v3.14c0 .31.21.68.8.56A11.5 11.5 0 0 0 23.5 12C23.5 5.73 18.27.5 12 .5z"/></svg>
                    GitHub
                </a>
            </div>

            {{-- Tab navigasi --}}
            <div class="flex flex-wrap items-center gap-1 p-1 rounded-xl bg-surface border border-border mb-4">
                @php
                    $tabs = [
                        'user-monitoring.visits-monitoring' => 'Kunjungan',
                        'user-monitoring.actions-monitoring' => 'Aksi',
                        'user-monitoring.authentications-monitoring' => 'Autentikasi',
                    ];
                @endphp
                @foreach ($tabs as $route => $label)
                    <a href="{{ route($route) }}"
                       class="px-4 py-2 rounded-lg text-small font-mono font-bold transition
                              {{ request()->routeIs($route)
                                    ? 'bg-brand text-foreground'
                                    : 'text-muted hover:text-foreground hover:bg-elevated/40' }}">
                        {{ $label }}
                    </a>
                @endforeach
            </div>

            {{-- Panel isi --}}
            <div class="bg-surface border border-border rounded-2xl p-4 sm:p-6">
                @if (session()->has('message'))
                    <div class="flex items-center gap-2 mb-4 px-4 py-3 rounded-lg bg-brand/10 border border-brand/30 text-brand-bright text-small font-mono">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
                        <span>{{ session()->get('message') }}</span>
                    </div>
                @endif

                {{-- Kontrol auto-refresh --}}
                <div class="flex items-center justify-end gap-2 mb-4 text-x-small font-mono text-muted">
                    <label class="inline-flex items-center gap-2 cursor-pointer select-none">
                        <input id="um-autorefresh-toggle" type="checkbox"
                               onchange="window.umToggleAutoRefresh(this.checked)"
                               class="w-4 h-4 accent-brand">
                        <span>Auto-refresh (10 dtk)</span>
                    </label>
                </div>

                @yield('content')
            </div>
        </div>
    </body>
</html>
