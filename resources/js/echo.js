import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Skema koneksi diambil dari env (bukan di-hardcode). Di lokal biasanya 'http'
// -> transport 'ws' polos; di produksi 'https' -> WAJIB 'wss' (browser memblokir
// ws:// dari halaman https). Membaca VITE_REVERB_SCHEME membuat SATU bundle bisa
// jalan di lokal & produksi tanpa ubah kode — asalkan `npm run build` dijalankan
// dengan .env produksi (VITE_* di-bake saat build).
const scheme = import.meta.env.VITE_REVERB_SCHEME ?? 'http';
const isSecure = scheme === 'https';

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,

    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: import.meta.env.VITE_REVERB_PORT,
    wssPort: import.meta.env.VITE_REVERB_PORT,

    forceTLS: isSecure,
    enabledTransports: isSecure ? ['ws', 'wss'] : ['ws'],
});

// GUARD socket ID. Livewire menyisipkan header X-Socket-ID = Echo.socketId() di
// SETIAP request update. Jika WebSocket belum terhubung (mis. server Reverb mati /
// masih handshake), socketId() mengembalikan `undefined`, dan header terkirim sebagai
// string literal "undefined". Server Pusher/Reverb menolaknya -> "Invalid socket ID
// undefined" (HTTP 500) saat broadcast()->toOthers(). Kita hook 'request' Livewire
// dan HAPUS header itu bila socket ID belum valid, sehingga server memperlakukannya
// sebagai "tanpa socket id" (broadcast tetap terkirim ke semua, hanya tak bisa
// meng-exclude pengirim — dampak sepele saat koneksi belum siap).
document.addEventListener('livewire:init', () => {
    if (!window.Livewire) return;
    window.Livewire.hook('request', ({ options }) => {
        const id = window.Echo?.socketId?.();
        if (!id || id === 'undefined') {
            if (options.headers) delete options.headers['X-Socket-ID'];
        }
    });
});

if (import.meta.env.DEV) {
    console.log('Echo Loaded', { scheme, host: import.meta.env.VITE_REVERB_HOST });
}
