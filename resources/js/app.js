import './bootstrap';

import Chart from 'chart.js/auto';
import toastStack from './toasts';
import typingGame from './typing-game';

window.Chart = Chart;

// Global, bukan Alpine.data(): typing-engine men-SPREAD komponen ini ke dalam
// x-data agar berbagi scope dengan @entangle('mainMode'/'subMode') yang ditulis
// dua arah oleh tombol pemilih mode. Alpine.data tidak dirancang untuk di-spread,
// jadi kontrak pemanggilannya dipertahankan apa adanya.
window.typingGame = typingGame;

// Alpine datang dari @livewireScripts (bukan di-start manual di sini), jadi
// komponen didaftarkan lewat hook alpine:init -- pola yang sama dipakai di
// seluruh view repo ini.
document.addEventListener('alpine:init', () => {
    window.Alpine.data('toastStack', toastStack);
});
