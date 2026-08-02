import { describe, it, expect, beforeEach, afterEach, vi } from 'vitest';
import typingGame from './typing-game';

/**
 * Jam mundur sebuah Clan War attempt yang di-resume.
 *
 * Bug yang dijaga berkas ini: setelah refresh, timer di layar berhenti. Angkanya benar pada
 * milidetik render -- server mengirim sisa waktu yang tepat -- tapi countdown-nya baru hidup pada
 * keystroke PERTAMA, karena ia dibaca dari `startTime`, dan `startTime` sengaja distempel saat
 * orang mulai mengetik (WPM diukur atas waktu mengetik, bukan atas waktu diam).
 *
 * Untuk attempt segar itu benar dan harus tetap begitu: jeda membaca teks memang gratis. Untuk
 * RESUME ia salah, dan salahnya mahal -- jam server terus berjalan sejak jangkar, jadi pemain
 * bisa duduk di depan countdown beku selama yang ia mau. Tekanan waktu yang mendefinisikan slot
 * itu hilang sama sekali, dan pemain melihatnya persis seperti "waktunya balik lagi".
 *
 * Perbaikannya memisahkan dua jam yang dulu satu: `countdownStart` (batas waktu) dan `startTime`
 * (WPM). Test di bawah menguji keduanya tetap terpisah.
 */

/** Komponen tanpa DOM, dengan mode dari sub-mode yang dipilih. */
function gameFor(warAttempt, { main = 'time', sub = '30' } = {}) {
    const game = typingGame('the quick brown fox', warAttempt);

    game.currentMain = main;
    game.currentSub = sub;
    game.calculateStats = () => {};
    game.finish = vi.fn(() => { game.isFinished = true; });

    return game;
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
});

describe('countdown pada attempt yang di-resume', () => {
    it('berjalan sejak halaman dimuat, tanpa menunggu keystroke', () => {
        const game = gameFor({ mode: 'time', config: '30', resume: true, chars: 40, remaining: 20, deadlineArmed: true });

        game.timerStart = 20;
        game.timer = 20;
        game.startClock();

        // Sepuluh detik berlalu tanpa satu pun tombol ditekan.
        vi.advanceTimersByTime(10_000);

        // Dulu ini tetap 20: jamnya tak pernah mulai, jadi refresh mengembalikan slot penuh.
        expect(game.timer).toBe(10);
    });

    it('menghabiskan sesi ketika jam server habis meski pemain tak mengetik', () => {
        const game = gameFor({ mode: 'time', config: '30', resume: true, chars: 40, remaining: 3, deadlineArmed: true });

        game.timerStart = 3;
        game.timer = 3;
        game.startClock();

        vi.advanceTimersByTime(4_000);

        expect(game.timer).toBe(0);
        expect(game.finish).toHaveBeenCalled();
    });

    it('tidak mencatat sampel WPM selama belum ada yang diketik', () => {
        // Jam mundur boleh jalan tanpa ketikan; histori WPM tidak. Sederet nol akan dibaca
        // pemeriksa konsistensi sebagai ketikan yang mustahil rata.
        const game = gameFor({ mode: 'time', config: '30', resume: true, chars: 40, remaining: 20, deadlineArmed: true });

        game.timerStart = 20;
        game.startClock();
        vi.advanceTimersByTime(5_000);

        expect(game.wpmHistory).toEqual([]);
        expect(game.rawHistory).toEqual([]);
    });

    it('mengukur WPM dari keystroke pertama, bukan dari saat jam mundur dimulai', () => {
        const game = gameFor({ mode: 'time', config: '30', resume: true, chars: 40, remaining: 20, deadlineArmed: true });

        game.timerStart = 20;
        game.startClock();

        // Lima detik menganggur, lalu mulai mengetik.
        vi.advanceTimersByTime(5_000);
        game.startTime = Date.now();
        game.totalKeystrokes = 10;
        vi.advanceTimersByTime(2_000);

        // Jam mundur melihat 7 detik; jam ketik hanya 2. Keduanya benar, dan keduanya berbeda --
        // itulah sebabnya keduanya tak boleh dibaca dari satu stempel.
        expect(game.timer).toBe(13);
        expect(game.wpmHistory.length).toBe(2);
    });

    it('idempoten: memasang jam dua kali tidak menjalankan dua interval', () => {
        // Mount memasangnya untuk resume, lalu keystroke pertama memanggilnya lagi. Dua interval
        // akan menghitung mundur dua kali lebih cepat.
        const game = gameFor({ mode: 'time', config: '30', resume: true, chars: 40, remaining: 20, deadlineArmed: true });

        game.timerStart = 20;
        game.startClock();
        game.startClock();

        vi.advanceTimersByTime(3_000);

        expect(game.timer).toBe(17);
    });
});

describe('countdown pada attempt segar', () => {
    it('tidak dipasang saat mount, sehingga waktu membaca tetap gratis', () => {
        // `deadlineArmed` bernilai false untuk attempt yang baru dibuka: GRACE_SECONDS di server
        // memang memaafkan detik-detik membaca sebelum keystroke pertama.
        const game = gameFor({ mode: 'time', config: '30', resume: false, chars: 0, remaining: 30, deadlineArmed: false });

        expect(game.warAttempt.deadlineArmed).toBe(false);
        expect(game.timerInterval).toBeNull();
    });
});
