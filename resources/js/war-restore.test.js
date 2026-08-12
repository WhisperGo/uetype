import { describe, it, expect } from 'vitest';
import typingGame from './typing-game';

/**
 * Apa yang boleh dan tak boleh dilakukan restore sebuah Clan War attempt.
 *
 * Memulihkan POSISI adalah inti fiturnya: pemain kembali ke kata tempat ia berhenti. Tapi
 * implementasi pertamanya juga mengkredit karakter yang dipulihkan sebagai keystroke:
 *
 *     this.totalKeystrokes = consumed;
 *     this.correctKeystrokes = consumed;
 *
 * Dua kebocoran sekaligus. Pertama, `startTime` baru diset pada keystroke pertama SETELAH
 * refresh, jadi karakter seluruh attempt dibagi durasi sesi terakhir saja -- WPM melar.
 * Kedua, semuanya ditandai BENAR, jadi tiap kesalahan sebelum refresh terhapus dan akurasi
 * tercuci. Server-lah yang memegang buku besar sekarang; klien hanya melaporkan sesinya sendiri.
 */

/** Bangun komponen sampai titik tepat sebelum restore, tanpa DOM. */
function gameAt(text, warAttempt) {
    const game = typingGame(text, warAttempt);

    game.targetArray = text.split('');

    // wordBounds persis seperti yang dibangun resetProgress().
    let start = 0;
    let wordIdx = 0;
    for (let i = 0; i < game.targetArray.length; i++) {
        if (game.targetArray[i] === ' ') {
            game.wordBounds[wordIdx] = { start, end: i - 1, space: i };
            start = i + 1;
            wordIdx++;
        }
    }
    game.wordBounds[wordIdx] = { start, end: game.targetArray.length - 1, space: null };

    return game;
}

const TEXT = 'the quick brown fox jumps over the lazy dog again';

describe('restoreWarProgress', () => {
    it('memulihkan posisi kata dari hitungan karakter server', () => {
        // 16 karakter = "the quick brown " pas; "fox " tidak muat.
        const game = gameAt(TEXT, { mode: 'words', config: '50', resume: true, chars: 16 });

        game.restoreWarProgress();

        expect(game.currentIndex).toBe(16);
        expect(game.currentWordIndex).toBe(3);
    });

    it('tidak mengkredit karakter yang dipulihkan sebagai keystroke', () => {
        const game = gameAt(TEXT, { mode: 'words', config: '50', resume: true, chars: 16 });

        game.restoreWarProgress();

        // Ini yang dulu bocor. Keystroke sesi ini masih nol -- pemain belum menekan apa pun
        // SEJAK refresh, dan jam yang mengukurnya pun belum jalan.
        expect(game.totalKeystrokes).toBe(0);
        expect(game.correctKeystrokes).toBe(0);
    });

    it('menandai karakter yang dipulihkan sebagai sudah benar di layar', () => {
        const game = gameAt(TEXT, { mode: 'words', config: '50', resume: true, chars: 16 });

        game.restoreWarProgress();

        // Tak dihitung bukan berarti tak digambar: pemain harus melihat kerjanya masih ada.
        expect(game.inputResults.slice(0, 16).every((r) => r === true)).toBe(true);
    });

    it('menghitung WPM langsung dari buku besar server, bukan dari sesi ini saja', () => {
        const game = gameAt(TEXT, {
            mode: 'words', config: '50', resume: true, chars: 16,
            carriedMs: 40000, carriedCorrect: 160, carriedTotal: 165,
        });

        game.restoreWarProgress();

        game.startTime = Date.now() - 20000; // 20 detik sesi ini
        game.correctKeystrokes = 40;
        game.totalKeystrokes = 40;
        game.calculateStats();

        // 200 karakter benar atas 60 detik = 40 WPM. Tanpa buku besar, klien menampilkan
        // 40/5/(20/60) = 24, lalu layar hasil melompat ke angka lain -- dua sumber kebenaran.
        expect(game.wpm).toBe(40);

        // Akurasi juga gabungan: 200/205, bukan 40/40 = 100%.
        expect(game.accuracy).toBe(98);
    });

    it('tidak memulihkan apa pun untuk survival', () => {
        const game = gameAt(TEXT, { mode: 'survival', config: 'hard', resume: true, chars: 16 });
        game.currentMain = 'survival';

        game.restoreWarProgress();

        expect(game.currentIndex).toBe(0);
        expect(game.currentWordIndex).toBe(0);
    });

    it('tidak memulihkan apa pun untuk sesi solo', () => {
        const game = gameAt(TEXT, null);

        game.restoreWarProgress();

        expect(game.currentIndex).toBe(0);
    });
});
