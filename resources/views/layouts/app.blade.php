<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ config('app.name', 'UEType') }}</title>
    @include('partials.favicon')

    <!-- Fonts: Space Grotesk untuk UI/heading, JetBrains Mono untuk area mengetik -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,100..800;1,100..800&family=Pixelify+Sans:wght@400..700&family=Press+Start+2P&family=Space+Grotesk:wght@400;500;600;700&display=swap"
        rel="stylesheet">

    <!-- Scripts -->
    @vite(['resources/css/app.css', 'resources/js/app.js'])
    @livewireStyles
    @livewireScripts
</head>

<body class="font-sans antialiased text-foreground bg-background selection:bg-brand selection:text-foreground">
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
                <span>&copy; 2026 uetype</span>
                <nav class="flex items-center gap-6">
                    <a href="/about" class="hover:text-foreground transition-colors">About</a>
                    <a href="/privacy-policy" class="hover:text-foreground transition-colors">Privacy</a>
                </nav>
            </div>
        </footer>

        @auth
            {{-- ===== NOTIFIKASI PERTEMANAN GLOBAL (toast) =====
                 Berlaku di SEMUA halaman: Player A bisa sedang mengetik/di multiplayer
                 saat permintaannya diterima. Komponen ini subscribe ke channel per-user
                 friends.{id} lewat WebSocket (Reverb) dan memunculkan toast saat ada
                 payload notifikasi (request masuk / permintaan diterima). --}}
            <div x-data="friendToasts()" x-init="init()"
                class="fixed z-[60] bottom-5 right-5 flex flex-col gap-3 w-80 max-w-[calc(100vw-2.5rem)] pointer-events-none">
                <template x-for="t in toasts" :key="t.id">
                    <div x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-4"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-x-0"
                        x-transition:leave-end="opacity-0 translate-x-4"
                        class="pointer-events-auto flex items-start gap-3 p-4 rounded-2xl border shadow-lg bg-surface border-white/10 backdrop-blur">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                            :class="t.type === 'accepted' ? 'bg-gold/15 text-gold' : 'bg-brand/15 text-brand-bright'">
                            <template x-if="t.type === 'accepted'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                            </template>
                            <template x-if="t.type !== 'accepted'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
                            </template>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-xs uppercase tracking-wider text-muted"
                                x-text="t.type === 'accepted' ? 'Friend request accepted' : 'New friend request'"></p>
                            <p class="mt-0.5 font-mono text-sm text-foreground break-words" x-text="t.message"></p>
                            <a href="{{ route('friends.index') }}" class="mt-1.5 inline-block font-mono text-xs text-brand-bright hover:underline">View →</a>
                        </div>
                        <button @click="dismiss(t.id)" class="text-muted hover:text-foreground shrink-0" aria-label="Dismiss">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </template>
            </div>

            <script>
                // Didaftarkan sekali; tahan re-eksekusi lewat flag global.
                if (!window.__friendToastsRegistered) {
                    window.__friendToastsRegistered = true;
                    document.addEventListener('alpine:init', () => {
                        window.Alpine.data('friendToasts', () => ({
                            toasts: [],
                            _seq: 0,
                            init() {
                                if (!window.Echo) return; // Echo dimuat via app.js
                                const channelName = `friends.{{ Auth::id() }}`;

                                // FIX toast dobel: dengan wire:navigate, layout & init() bisa
                                // jalan berkali-kali sehingga .listen() menumpuk callback pada
                                // channel yang sama -> 1 event = banyak toast. Lepas dulu listener
                                // lama, lalu pasang SATU listener bersih.
                                const channel = window.Echo.channel(channelName);
                                channel.stopListening('.friendship.updated');
                                channel.listen('.friendship.updated', (e) => {
                                    // Satu-satunya subscriber Echo untuk friends.{id}. Selain
                                    // memunculkan toast, teruskan sebagai event window supaya
                                    // halaman Friends bisa menyegarkan diri TANPA subscribe
                                    // channel yang sama (mencegah listener dobel & toast dobel).
                                    window.dispatchEvent(new CustomEvent('friendship-updated-remote'));

                                    if (e && e.notification && e.notification.message) {
                                        this.push(e.notification);
                                    }
                                });

                                // Status online/offline teman: tanpa toast, hanya menyegarkan
                                // daftar teman supaya titik status menyala/padam real-time.
                                channel.stopListening('.presence.updated');
                                channel.listen('.presence.updated', () => {
                                    window.dispatchEvent(new CustomEvent('friendship-updated-remote'));
                                });
                            },
                            push(n) {
                                const id = ++this._seq;
                                this.toasts.push({ id, type: n.type || 'request', message: n.message });
                                setTimeout(() => this.dismiss(id), 6000); // auto-dismiss 6s
                            },
                            dismiss(id) {
                                this.toasts = this.toasts.filter((t) => t.id !== id);
                            },
                        }));
                    });
                }
            </script>

            {{-- ===== NOTIFIKASI CLAN GLOBAL (toast) =====
                 Sama persis dengan toast pertemanan di atas, tapi untuk channel
                 per-user clan.{id}: permintaan gabung masuk (khusus leader) &
                 permintaan diterima. --}}
            <div x-data="clanToasts()" x-init="init()"
                class="fixed z-[60] bottom-5 right-5 flex flex-col gap-3 w-80 max-w-[calc(100vw-2.5rem)] pointer-events-none">
                <template x-for="t in toasts" :key="t.id">
                    <div x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-4"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-x-0"
                        x-transition:leave-end="opacity-0 translate-x-4"
                        class="pointer-events-auto flex items-start gap-3 p-4 rounded-2xl border shadow-lg bg-surface border-white/10 backdrop-blur">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                            :class="t.type === 'accepted' ? 'bg-gold/15 text-gold' : 'bg-brand/15 text-brand-bright'">
                            <template x-if="t.type === 'accepted'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                            </template>
                            <template x-if="t.type !== 'accepted'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-3-3.87M9 20H4v-1a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-1a4 4 0 10-4-4" /></svg>
                            </template>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-xs uppercase tracking-wider text-muted"
                                x-text="t.type === 'accepted' ? 'Clan request accepted' : (t.type === 'war-result' ? 'Clan War result' : 'Clan update')"></p>
                            <p class="mt-0.5 font-mono text-sm text-foreground break-words" x-text="t.message"></p>
                            <a :href="t.type === 'war-result' ? '{{ route('clan-war.index') }}' : '{{ route('clans.index') }}'" class="mt-1.5 inline-block font-mono text-xs text-brand-bright hover:underline">View →</a>
                        </div>
                        <button @click="dismiss(t.id)" class="text-muted hover:text-foreground shrink-0" aria-label="Dismiss">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </template>
            </div>

            <script>
                // Didaftarkan sekali; tahan re-eksekusi lewat flag global.
                if (!window.__clanToastsRegistered) {
                    window.__clanToastsRegistered = true;
                    document.addEventListener('alpine:init', () => {
                        window.Alpine.data('clanToasts', () => ({
                            toasts: [],
                            _seq: 0,
                            init() {
                                if (!window.Echo) return; // Echo dimuat via app.js
                                const channelName = `clan.{{ Auth::id() }}`;

                                // FIX toast dobel: sama seperti friendToasts -- lepas
                                // listener lama dulu sebelum memasang yang baru.
                                const channel = window.Echo.channel(channelName);
                                channel.stopListening('.clan.updated');
                                channel.listen('.clan.updated', (e) => {
                                    window.dispatchEvent(new CustomEvent('clan-updated-remote'));

                                    if (e && e.notification && e.notification.message) {
                                        this.push(e.notification);
                                    }
                                });
                            },
                            push(n) {
                                const id = ++this._seq;
                                this.toasts.push({ id, type: n.type || 'request', message: n.message });
                                setTimeout(() => this.dismiss(id), 6000); // auto-dismiss 6s
                            },
                            dismiss(id) {
                                this.toasts = this.toasts.filter((t) => t.id !== id);
                            },
                        }));
                    });
                }
            </script>
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
