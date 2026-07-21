{{-- Main authenticated app layout: nav, optional header, slot content, footer, and global overlays (toasts, chat, presence heartbeat). --}}
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
    @livewireStyles
    @livewireScripts
</head>

<body class="font-mono antialiased text-foreground bg-background selection:bg-brand selection:text-foreground">
    <div class="min-h-screen flex flex-col bg-background">
        @include('layouts.navigation')

        @isset($header)
            <header class="border-b border-white/5">
                <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8">
                    {{ $header }}
                </div>
            </header>
        @endisset

        <main class="flex-1">
            {{ $slot }}
        </main>

        @auth
            @include('layouts.sign-out-confirmation')
        @endauth

        <footer class="border-t border-white/5">
            <div class="max-w-7xl mx-auto py-6 px-4 sm:px-6 lg:px-8 flex flex-col sm:flex-row items-center justify-between gap-3 text-x-small text-muted">
                <span>&copy; 2026 UeType</span>
                <nav class="flex items-center gap-6">
                    <a href="/about" class="hover:text-foreground transition-colors">{{ __('common.footer.about') }}</a>
                    <a href="/privacy-policy" class="hover:text-foreground transition-colors">{{ __('common.footer.privacy') }}</a>
                </nav>
            </div>
        </footer>

        @auth
            <x-toast-stack />

            {{-- ===== GLOBAL CHAT OVERLAY =====
                 A chat drawer openable from any page, mounted once here (outside
                 {{ '{{ $slot }}' }}) so it survives across wire:navigate like the
                 toast above. Echo is still subscribed only by <x-toast-stack />;
                 this overlay listens to the same window events
                 (message-received-remote/-mutated-remote). --}}
            <livewire:chat-overlay />

            {{-- ===== PRESENCE HEARTBEAT =====
                 A lightweight ping to /heartbeat every ~30s marks the user as still
                 online (last_seen_at is updated). The server broadcasts to friends
                 only on the offline->online transition, so this ping is cheap.
                 Paused while the tab is hidden (to save resources) and pings again
                 immediately when the tab becomes visible so status recovers quickly. --}}
            <script>
                if (!window.__presenceHeartbeatRegistered) {
                    window.__presenceHeartbeatRegistered = true;
                    (function () {
                        const url = '{{ route('presence.heartbeat') }}';
                        const token = document.querySelector('meta[name="csrf-token"]')?.content;
                        const INTERVAL = 30000; // 30s; server online threshold is 60s
                        let timer = null;

                        const ping = () => {
                            if (document.hidden || !token) return;
                            fetch(url, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                                keepalive: true,
                            }).catch(() => {}); // swallow network errors; the next ping retries
                        };

                        const start = () => {
                            if (timer) return;
                            ping();
                            timer = setInterval(ping, INTERVAL);
                        };
                        const stop = () => {
                            if (timer) { clearInterval(timer); timer = null; }
                        };

                        document.addEventListener('visibilitychange', () => {
                            document.hidden ? stop() : start();
                        });

                        start();
                    })();
                }
            </script>
        @endauth
    </div>

    <script>
        document.addEventListener('livewire:init', () => {
            Livewire.hook('request', ({
                fail
            }) => {
                fail(({
                    status,
                    preventDefault
                }) => {
                    if (status === 419) {
                        window.location.reload();
                        preventDefault();
                    }
                });
            });
        });
    </script>
</body>

</html>
