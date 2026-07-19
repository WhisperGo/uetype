/**
 * Satu tumpukan toast global untuk SEMUA notifikasi (pertemanan, clan, chat).
 *
 * Dulu ada tiga komponen Alpine terpisah yang masing-masing merender container
 * sendiri -- dan ketiganya memakai posisi identik (`fixed bottom-5 right-5 z-[60]`).
 * Akibatnya toast teman dan toast chat yang datang bersamaan saling MENIMPA,
 * bukan menumpuk. Menyatukannya memperbaiki bug itu sekaligus membuang ~280
 * baris markup yang tersalin tiga kali.
 *
 * Langganan Echo tetap per-kanal (payload & aturan tampilnya beda-beda), tapi
 * semuanya mendorong ke SATU antrean lewat push().
 */

const AUTO_DISMISS_MS = 6000;

/**
 * @param {object} config disuplai dari Blade -- label terjemahan, URL route, dan
 *   identitas user. Semua yang perlu dirender server tinggal di sini supaya modul
 *   ini tetap JavaScript murni yang bisa di-lint & di-bundle.
 */
export default function toastStack(config) {
    return {
        toasts: [],
        _seq: 0,

        init() {
            if (!window.Echo) return; // Echo dimuat via app.js

            this.listenFriends();
            this.listenClan();
            this.listenChat();
        },

        // ---- LANGGANAN ----

        listenFriends() {
            const channel = window.Echo.channel(`friends.${config.userId}`);

            // wire:navigate bisa menjalankan init() berkali-kali; lepas listener lama
            // dulu agar callback tak menumpuk (1 event = 1 toast).
            channel.stopListening('.friendship.updated');
            channel.listen('.friendship.updated', (e) => {
                // Satu-satunya subscriber friends.{id}: selain toast, teruskan sebagai
                // event window agar halaman Friends menyegarkan diri tanpa subscribe lagi.
                window.dispatchEvent(new CustomEvent('friendship-updated-remote'));

                if (!e?.notification?.message) return;

                const type = e.notification.type || 'request';
                this.push({
                    icon: type === 'accepted' ? 'check' : 'user-plus',
                    tone: type === 'accepted' ? 'gold' : 'brand',
                    title: type === 'accepted' ? config.labels.friend.accepted : config.labels.friend.request,
                    message: e.notification.message,
                    href: config.routes.friends,
                });
            });

            // Status online/offline: tanpa toast, hanya menyegarkan daftar teman.
            channel.stopListening('.presence.updated');
            channel.listen('.presence.updated', () => {
                window.dispatchEvent(new CustomEvent('friendship-updated-remote'));
            });
        },

        listenClan() {
            const channel = window.Echo.channel(`clan.${config.userId}`);

            channel.stopListening('.clan.updated');
            channel.listen('.clan.updated', (e) => {
                window.dispatchEvent(new CustomEvent('clan-updated-remote'));

                if (!e?.notification?.message) return;

                const type = e.notification.type || 'request';
                const isWar = ['war-result', 'war-challenge', 'war-accepted', 'war-declined'].includes(type);

                this.push({
                    icon: clanIcon(type),
                    tone: clanTone(type),
                    title: config.labels.clan[type] || config.labels.clan.update,
                    message: e.notification.message,
                    href: isWar ? config.routes.clanWar : config.routes.clans,
                });
            });
        },

        listenChat() {
            const dmChannel = window.Echo.channel(`chat.${config.userId}`);

            dmChannel.stopListening('.dm.sent');
            dmChannel.listen('.dm.sent', (e) => {
                // Payload lengkap agar halaman chat bisa menampilkan pesan seketika.
                window.dispatchEvent(new CustomEvent('message-received-remote', {
                    detail: { ...e, kind: 'dm' },
                }));

                // Toast hanya kalau percakapan dengan pengirim ini tak sedang dibuka.
                if (e?.body && e.senderUsername && !this.isViewingDm(e.senderUsername)) {
                    this.push({
                        icon: 'chat',
                        tone: 'brand',
                        title: e.senderUsername,
                        message: e.body,
                        href: `${config.routes.chat}?mode=dm&with=${encodeURIComponent(e.senderUsername)}`,
                    });
                }
            });

            // Edit/hapus DM: teruskan payload agar bubble di-patch langsung di client.
            this.forwardMutations(dmChannel);

            if (!config.clanId) return;

            // Clan chat: channel per-clan, semua anggota subscribe yang sama.
            const clanChannel = window.Echo.channel(`clan-chat.${config.clanId}`);

            clanChannel.stopListening('.clan-message.sent');
            clanChannel.listen('.clan-message.sent', (e) => {
                window.dispatchEvent(new CustomEvent('message-received-remote', {
                    detail: { ...e, kind: 'clan' },
                }));

                // Jangan toast pesan sendiri, atau kalau chat clan sedang dibuka.
                if (e?.body && e.senderUsername && e.senderId !== config.userId && !this.isViewingClan()) {
                    this.push({
                        icon: 'chat',
                        tone: 'brand',
                        title: e.senderUsername,
                        message: e.body,
                        href: `${config.routes.chat}?mode=clan`,
                    });
                }
            });

            this.forwardMutations(clanChannel);
        },

        forwardMutations(channel) {
            channel.stopListening('.message.edited');
            channel.listen('.message.edited', (e) => window.dispatchEvent(
                new CustomEvent('message-mutated-remote', { detail: { ...e, action: 'edited' } })
            ));

            channel.stopListening('.message.deleted');
            channel.listen('.message.deleted', (e) => window.dispatchEvent(
                new CustomEvent('message-mutated-remote', { detail: { ...e, action: 'deleted' } })
            ));
        },

        // ---- PENEKANAN TOAST ----
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

        // ---- ANTREAN ----

        push(toast) {
            const id = ++this._seq;

            this.toasts.push({ id, ...toast });

            setTimeout(() => this.dismiss(id), AUTO_DISMISS_MS);
        },

        dismiss(id) {
            this.toasts = this.toasts.filter((t) => t.id !== id);
        },
    };
}

function clanIcon(type) {
    if (type === 'accepted' || type === 'war-accepted') return 'check';
    if (type === 'war-declined') return 'x';
    if (type === 'war-challenge') return 'swords';

    return 'group';
}

function clanTone(type) {
    if (type === 'accepted' || type === 'war-accepted') return 'gold';
    if (type === 'war-declined') return 'danger';

    return 'brand';
}
