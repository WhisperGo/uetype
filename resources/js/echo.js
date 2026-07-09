import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Skema dari env: 'http' -> ws, 'https' -> wss (browser memblokir ws:// dari halaman
// https). VITE_* di-bake saat `npm run build`, jadi build ulang tiap kali .env berubah.
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

// Guard socket ID: saat WebSocket belum terhubung, Livewire tetap mengirim header
// X-Socket-ID bernilai "undefined" -> Reverb menolak (500) pada broadcast()->toOthers().
// Hapus header itu bila socket ID belum valid; broadcast tetap jalan, hanya tak bisa
// meng-exclude pengirim.
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
