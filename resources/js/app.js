import './bootstrap';

import Chart from 'chart.js/auto';
import toastStack from './toasts';
import navBadges from './nav-badges';
import typingGame from './typing-game';
import chatOverlayDock from './chat-dock';
import createChatRuntime from './chat-runtime';
import './race-arena';
import './race-echo';

window.Chart = Chart;

// Global, bukan Alpine.data(): typing-engine men-SPREAD komponen ini ke dalam
// x-data agar berbagi scope dengan @entangle('mainMode'/'subMode') yang ditulis
// dua arah oleh tombol pemilih mode. Alpine.data tidak dirancang untuk di-spread,
// jadi kontrak pemanggilannya dipertahankan apa adanya.
window.typingGame = typingGame;

// Runtime chat dipanggil dari @script tiap komponen chat (halaman penuh &
// overlay) karena butuh $wire dan 4 nilai dari Blade: auth()->id(),
// route('chat.send'), dan dua label terjemahan. Modul hanya menyediakan
// factory-nya; Blade yang menyuntikkan nilainya.
window.createChatRuntime = createChatRuntime;

// Alpine datang dari @livewireScripts (bukan di-start manual di sini), jadi
// komponen didaftarkan lewat hook alpine:init -- pola yang sama dipakai di
// seluruh view repo ini.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('toastStack', toastStack);
    window.Alpine.data('navBadges', navBadges);
    window.Alpine.data('chatOverlayDock', chatOverlayDock);
});
