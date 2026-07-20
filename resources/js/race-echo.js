/**
 * Langganan Echo untuk multiplayer: channel lifecycle room (join/ready/start)
 * dan channel race berfrekuensi tinggi (posisi lawan).
 *
 * Dipisah dari race-arena.js karena butuh `Livewire` global. Blok aslinya ada di
 * @script (bukan @assets) justru karena alasan itu; di modul, ketergantungan yang
 * sama dipenuhi dengan menunggu event `livewire:init` -- saat modul dieksekusi,
 * Livewire belum tentu ada.
 */
// Komponen Alpine untuk panel chat lobby (partials/room-chat.blade.php). Didaftarkan
// di 'alpine:init' agar tersedia sebelum view di-mount. State pesan hidup di sini
// (bukan Livewire) karena chat bersifat broadcast-only & sesaat.
document.addEventListener('alpine:init', () => {
    // joinLabel/leaveLabel: template lokal dengan ':name', diisi dari view.
    window.Alpine.data('roomChat', ({ me, youLabel, joinLabel, leaveLabel }) => ({
        me,
        youLabel,
        joinLabel,
        leaveLabel,
        draft: '',
        messages: [],

        init() {
            // Pesan dari peserta LAIN (di-relay race-echo listener '.room.message').
            this._onRemote = (e) => {
                const d = e.detail || {};
                // Abaikan gema pesan sendiri kalau sempat lolos (kirim pakai ->toOthers,
                // tapi ini jaga-jaga bila suatu saat berubah jadi broadcast ke semua).
                if (Number(d.senderId) === Number(this.me)) { return; }
                this.push({ username: d.senderUsername, body: d.body, mine: false });
            };
            window.addEventListener('room-message-received', this._onRemote);

            // Notif kehadiran (join/leave) -> pesan sistem di tengah.
            this._onPresence = (e) => {
                const d = e.detail || {};
                const tpl = d.action === 'leave' ? this.leaveLabel : this.joinLabel;
                this.push({ system: true, body: tpl.replace(':name', d.username || '') });
            };
            window.addEventListener('room-presence-changed', this._onPresence);
        },

        destroy() {
            window.removeEventListener('room-message-received', this._onRemote);
            window.removeEventListener('room-presence-changed', this._onPresence);
        },

        send() {
            const body = this.draft.trim();
            if (body === '') { return; }
            this.draft = '';
            // Optimistic: tampilkan langsung di sisi pengirim.
            this.push({ username: this.youLabel, body, mine: true });
            // Siarkan ke peserta lain (server broadcast ->toOthers, tak menyimpan).
            this.$wire.sendRoomMessage(body);
        },

        push(msg) {
            this.messages.push(msg);
            // Batasi buffer agar tak tumbuh tanpa batas selama room panjang.
            if (this.messages.length > 200) { this.messages.splice(0, this.messages.length - 200); }
            this.$nextTick(() => {
                if (this.$refs.log) { this.$refs.log.scrollTop = this.$refs.log.scrollHeight; }
            });
        },
    }));
});

document.addEventListener('livewire:init', () => {
    let currentRoomChannel = null;
    let currentRaceChannel = null;

    const leaveChannels = () => {
        if (currentRoomChannel) {
            window.Echo.leave(currentRoomChannel);
            currentRoomChannel = null;
        }
        if (currentRaceChannel) {
            window.Echo.leave(currentRaceChannel);
            currentRaceChannel = null;
        }
        // Bersihkan posisi lawan agar room berikutnya tak kebawa state basi.
        if (window.Alpine && window.Alpine.store('race')) {
            window.Alpine.store('race').reset();
        }
    };

    Livewire.on('subscribe-room', (event) => {
        const room = event.room;

        leaveChannels();

        // Channel lifecycle (low-frequency): join/ready/leave/start/finish -> tetap re-render Livewire penuh.
        currentRoomChannel = `room.${room}`;
        window.Echo
            .channel(currentRoomChannel)
            .listen('.room.updated', () => {
                Livewire.dispatch('room-updated');
            })
            // Chat lobby: numpang channel room yang sama. Broadcast-only (tak disimpan),
            // jadi cukup relay ke window event -> ditangkap Alpine (roomChat) di view.
            .listen('.room.message', (e) => {
                window.dispatchEvent(new CustomEvent('room-message-received', { detail: e }));
            })
            // Notif kehadiran (join/leave) -> pesan sistem di tengah panel chat.
            .listen('.room.presence', (e) => {
                window.dispatchEvent(new CustomEvent('room-presence-changed', { detail: e }));
            });

        // Channel race (high-frequency): posisi maskot lawan diterapkan langsung ke Alpine store, tanpa round-trip Livewire.
        currentRaceChannel = `race.${room}`;
        window.Echo
            .channel(currentRaceChannel)
            .listen('.race.progress', (e) => {
                if (window.Alpine && window.Alpine.store('race')) {
                    window.Alpine.store('race').apply(e.userId, e.progressData || {});
                }
            })
            .listen('.race.sudden_death', (e) => {
                // Semua klien hitung sisa waktu dari timestamp akhir server yang sama -> tersinkron.
                const endMs = new Date(e.endTimeIso).getTime();
                const remaining = Math.max(0, Math.ceil((endMs - Date.now()) / 1000));
                window.dispatchEvent(new CustomEvent('race-sudden-death', {
                    detail: { remaining }
                }));
            });
    });

    Livewire.on('leave-room', () => {
        leaveChannels();
    });
});
