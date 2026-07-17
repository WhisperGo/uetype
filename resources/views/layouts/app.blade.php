<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">

    <title>{{ $pageTitle ?? config('app.name', 'UeType') }}</title>
    @include('partials.favicon')

    <!-- Fonts: JetBrains Mono untuk semua teks readable; Pixelify Sans & Press Start 2P untuk aksen game -->
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link
        href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,100..800;1,100..800&family=Pixelify+Sans:wght@400..700&family=Press+Start+2P&display=swap"
        rel="stylesheet">

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
                                x-text="t.type === 'accepted' ? @js(__('notif.friend.accepted')) : @js(__('notif.friend.request'))"></p>
                            <p class="mt-0.5 font-mono text-sm text-foreground break-words" x-text="t.message"></p>
                            <a href="{{ route('friends.index') }}" class="mt-1.5 inline-block font-mono text-xs text-brand-bright hover:underline">{{ __('notif.view') }}</a>
                        </div>
                        <button @click="dismiss(t.id)" class="text-muted hover:text-foreground shrink-0" aria-label="{{ __('notif.dismiss') }}">
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

                                // wire:navigate bisa menjalankan init() berkali-kali; lepas listener
                                // lama dulu agar callback tak menumpuk (1 event = 1 toast).
                                const channel = window.Echo.channel(channelName);
                                channel.stopListening('.friendship.updated');
                                channel.listen('.friendship.updated', (e) => {
                                    // Satu-satunya subscriber friends.{id}: selain toast, teruskan sebagai
                                    // event window agar halaman Friends menyegarkan diri tanpa subscribe lagi.
                                    window.dispatchEvent(new CustomEvent('friendship-updated-remote'));

                                    if (e && e.notification && e.notification.message) {
                                        this.push(e.notification);
                                    }
                                });

                                // Status online/offline: tanpa toast, hanya menyegarkan daftar teman.
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
                            :class="{
                                'bg-gold/15 text-gold': t.type === 'accepted' || t.type === 'war-accepted',
                                'bg-danger/15 text-danger': t.type === 'war-declined',
                                'bg-brand/15 text-brand-bright': t.type !== 'accepted' && t.type !== 'war-accepted' && t.type !== 'war-declined',
                            }">
                            {{-- Diterima (gabung clan / war): centang --}}
                            <template x-if="t.type === 'accepted' || t.type === 'war-accepted'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                            </template>
                            {{-- Ditolak: silang --}}
                            <template x-if="t.type === 'war-declined'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                            </template>
                            {{-- Tantangan war masuk: pedang menyilang --}}
                            <template x-if="t.type === 'war-challenge'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.5 17.5L3 6V3h3l11.5 11.5M13 19l6-6M16 16l4 4M19 21l2-2" /></svg>
                            </template>
                            {{-- Lainnya (permintaan gabung clan / update umum): ikon grup --}}
                            <template x-if="t.type !== 'accepted' && t.type !== 'war-accepted' && t.type !== 'war-declined' && t.type !== 'war-challenge'">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-3-3.87M9 20H4v-1a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-1a4 4 0 10-4-4" /></svg>
                            </template>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-xs uppercase tracking-wider text-muted"
                                x-text="{
                                    'accepted': @js(__('notif.clan.accepted')),
                                    'war-result': @js(__('notif.clan.war_result')),
                                    'war-challenge': @js(__('notif.clan.war_challenge')),
                                    'war-accepted': @js(__('notif.clan.war_accepted')),
                                    'war-declined': @js(__('notif.clan.war_declined')),
                                }[t.type] || @js(__('notif.clan.update'))"></p>
                            <p class="mt-0.5 font-mono text-sm text-foreground break-words" x-text="t.message"></p>
                            <a :href="['war-result', 'war-challenge', 'war-accepted', 'war-declined'].includes(t.type) ? '{{ route('clan-war.index') }}' : '{{ route('clans.index') }}'" class="mt-1.5 inline-block font-mono text-xs text-brand-bright hover:underline">{{ __('notif.view') }}</a>
                        </div>
                        <button @click="dismiss(t.id)" class="text-muted hover:text-foreground shrink-0" aria-label="{{ __('notif.dismiss') }}">
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

                                // Lepas listener lama dulu (sama seperti friendToasts).
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

            {{-- ===== NOTIFIKASI CHAT GLOBAL (toast) =====
                 Sama persis dengan toast pertemanan/clan di atas, tapi mendengar
                 DUA channel sekaligus: chat.{id} (DM masuk) dan, kalau user
                 sedang punya clan, clan-chat.{clanId} (pesan clan masuk). --}}
            <div x-data="chatToasts()" x-init="init()"
                class="fixed z-[60] bottom-5 right-5 flex flex-col gap-3 w-80 max-w-[calc(100vw-2.5rem)] pointer-events-none">
                <template x-for="t in toasts" :key="t.id">
                    <div x-transition:enter="transition ease-out duration-300"
                        x-transition:enter-start="opacity-0 translate-y-4"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        x-transition:leave="transition ease-in duration-200"
                        x-transition:leave-start="opacity-100 translate-x-0"
                        x-transition:leave-end="opacity-0 translate-x-4"
                        class="pointer-events-auto flex items-start gap-3 p-4 rounded-2xl border shadow-lg bg-surface border-white/10 backdrop-blur">
                        <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0 bg-brand/15 text-brand-bright">
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.17 0-2.29-.2-3.32-.56L3 21l1.56-4.68C3.57 15.19 3 13.65 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="font-mono text-xs uppercase tracking-wider text-muted" x-text="t.senderUsername"></p>
                            <p class="mt-0.5 font-mono text-sm text-foreground break-words" x-text="t.message"></p>
                            <a :href="t.clanId ? '{{ route('chat.index') }}?mode=clan' : `{{ route('chat.index') }}?mode=dm&with=${t.senderUsername}`" wire:navigate class="mt-1.5 inline-block font-mono text-xs text-brand-bright hover:underline">{{ __('notif.reply') }}</a>
                        </div>
                        <button @click="dismiss(t.id)" class="text-muted hover:text-foreground shrink-0" aria-label="{{ __('notif.dismiss') }}">
                            <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                        </button>
                    </div>
                </template>
            </div>

            <script>
                // Didaftarkan sekali; tahan re-eksekusi lewat flag global.
                if (!window.__chatToastsRegistered) {
                    window.__chatToastsRegistered = true;
                    document.addEventListener('alpine:init', () => {
                        window.Alpine.data('chatToasts', () => ({
                            toasts: [],
                            _seq: 0,
                            init() {
                                if (!window.Echo) return; // Echo dimuat via app.js

                                // DM: channel per-user chat.{id}.
                                const dmChannel = window.Echo.channel(`chat.{{ Auth::id() }}`);
                                dmChannel.stopListening('.dm.sent');
                                dmChannel.listen('.dm.sent', (e) => {
                                    // Payload lengkap agar halaman chat bisa menampilkan pesan seketika.
                                    window.dispatchEvent(new CustomEvent('message-received-remote', {
                                        detail: { ...e, kind: 'dm' },
                                    }));
                                    // Toast hanya kalau percakapan dengan pengirim ini tak sedang dibuka.
                                    if (e && e.body && e.senderUsername
                                        && !this.isViewingDm(e.senderUsername)) {
                                        this.push(e);
                                    }
                                });
                                // Edit/hapus DM: teruskan payload agar bubble di-patch langsung di client.
                                dmChannel.stopListening('.message.edited');
                                dmChannel.listen('.message.edited', (e) => window.dispatchEvent(new CustomEvent('message-mutated-remote', { detail: { ...e, action: 'edited' } })));
                                dmChannel.stopListening('.message.deleted');
                                dmChannel.listen('.message.deleted', (e) => window.dispatchEvent(new CustomEvent('message-mutated-remote', { detail: { ...e, action: 'deleted' } })));

                                @if (Auth::user()->clan)
                                    // Clan chat: channel per-clan, semua anggota subscribe yang sama.
                                    const clanChannel = window.Echo.channel(`clan-chat.{{ Auth::user()->clan->id }}`);
                                    clanChannel.stopListening('.clan-message.sent');
                                    clanChannel.listen('.clan-message.sent', (e) => {
                                        window.dispatchEvent(new CustomEvent('message-received-remote', {
                                            detail: { ...e, kind: 'clan' },
                                        }));
                                        // Jangan toast pesan sendiri, atau kalau chat clan sedang dibuka.
                                        if (e && e.body && e.senderUsername
                                            && e.senderId !== {{ Auth::id() }}
                                            && !this.isViewingClan()) {
                                            this.push(e);
                                        }
                                    });
                                    clanChannel.stopListening('.message.edited');
                                    clanChannel.listen('.message.edited', (e) => window.dispatchEvent(new CustomEvent('message-mutated-remote', { detail: { ...e, action: 'edited' } })));
                                    clanChannel.stopListening('.message.deleted');
                                    clanChannel.listen('.message.deleted', (e) => window.dispatchEvent(new CustomEvent('message-mutated-remote', { detail: { ...e, action: 'deleted' } })));
                                @endif
                            },
                            // Baca URL: percakapan mana yang sedang dibuka (agar toast-nya dilewati).
                            isViewingDm(username) {
                                if (location.pathname.endsWith('/chat')) {
                                    const p = new URLSearchParams(location.search);
                                    if (p.get('mode') === 'dm' && p.get('with') === username) return true;
                                }
                                // Overlay global juga bisa sedang membuka thread yang sama.
                                const s = window.__chatOverlayState;
                                return !!(s && s.open && s.mode === 'dm' && s.withUsername === username);
                            },
                            isViewingClan() {
                                if (location.pathname.endsWith('/chat')) {
                                    if (new URLSearchParams(location.search).get('mode') === 'clan') return true;
                                }
                                const s = window.__chatOverlayState;
                                return !!(s && s.open && s.mode === 'clan');
                            },
                            push(n) {
                                const id = ++this._seq;
                                this.toasts.push({ id, senderUsername: n.senderUsername, message: n.body, clanId: n.clanId || null });
                                setTimeout(() => this.dismiss(id), 6000); // auto-dismiss 6s
                            },
                            dismiss(id) {
                                this.toasts = this.toasts.filter((t) => t.id !== id);
                            },
                        }));
                    });
                }
            </script>

            {{-- ===== CHAT OVERLAY GLOBAL =====
                 Drawer chat yang bisa dibuka dari halaman mana pun, mounted
                 sekali di sini (di luar {{ '{{ $slot }}' }}) supaya bertahan
                 lintas wire:navigate seperti toast di atas. Echo tetap hanya
                 di-subscribe oleh chatToasts(); overlay ini mendengarkan event
                 window yang sama (message-received-remote/-mutated-remote). --}}
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
