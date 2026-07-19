<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $pageTitle ?? config('app.name', 'UeType') }}</title>
    @include('partials.favicon')

    <!-- Fonts: JetBrains Mono untuk semua teks readable; Pixelify Sans & Press Start 2P untuk aksen game -->
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

            {{-- ===== CHAT OVERLAY GLOBAL =====
                 Drawer chat yang bisa dibuka dari halaman mana pun, mounted
                 sekali di sini (di luar {{ '{{ $slot }}' }}) supaya bertahan
                 lintas wire:navigate seperti toast di atas. Echo tetap hanya
                 di-subscribe oleh <x-toast-stack />; overlay ini mendengarkan
                 event window yang sama (message-received-remote/-mutated-remote). --}}
            <livewire:chat-overlay />

            {{-- ===== HEARTBEAT PRESENCE =====
                 Ping ringan ke /heartbeat tiap ~30 detik menandai user masih
                 online (last_seen_at diperbarui). Server menyiarkan ke teman
                 hanya saat transisi offline->online, jadi ping ini murah.
                 Dijeda saat tab tersembunyi (hemat) & langsung ping lagi saat
                 tab kembali terlihat supaya status cepat pulih. --}}
            <script>
                if (!window.__presenceHeartbeatRegistered) {
                    window.__presenceHeartbeatRegistered = true;
                    (function () {
                        const url = '{{ route('presence.heartbeat') }}';
                        const token = document.querySelector('meta[name="csrf-token"]')?.content;
                        const INTERVAL = 30000; // 30s; ambang online server 60s
                        let timer = null;

                        const ping = () => {
                            if (document.hidden || !token) return;
                            fetch(url, {
                                method: 'POST',
                                headers: { 'X-CSRF-TOKEN': token, 'X-Requested-With': 'XMLHttpRequest' },
                                keepalive: true,
                            }).catch(() => {}); // diamkan error jaringan; ping berikutnya coba lagi
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
