import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

window.Pusher = Pusher;

// Scheme from env: 'http' -> ws, 'https' -> wss (browsers block ws:// from an https
// page). VITE_* is baked at `npm run build`, so rebuild whenever .env changes.
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

// Socket-ID guard: before the WebSocket connects, Livewire still sends an X-Socket-ID
// header of "undefined" -> Reverb rejects (500) on broadcast()->toOthers(). Drop that
// header when the socket ID isn't valid yet; the broadcast still runs, it just can't
// exclude the sender.
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
