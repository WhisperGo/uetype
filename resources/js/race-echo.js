/**
 * Langganan Echo untuk multiplayer: channel lifecycle room (join/ready/start)
 * dan channel race berfrekuensi tinggi (posisi lawan).
 *
 * Dipisah dari race-arena.js karena butuh `Livewire` global. Blok aslinya ada di
 * @script (bukan @assets) justru karena alasan itu; di modul, ketergantungan yang
 * sama dipenuhi dengan menunggu event `livewire:init` -- saat modul dieksekusi,
 * Livewire belum tentu ada.
 */
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
