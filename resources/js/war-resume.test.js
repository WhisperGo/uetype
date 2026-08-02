import { describe, it, expect } from 'vitest';
import { resumePosition } from './war-resume';

/**
 * wordBounds persis seperti yang dibangun typing-game.js resetProgress(): satu entri per kata,
 * `space` menunjuk indeks spasi setelahnya dan null di kata terakhir.
 */
function boundsFor(text) {
    const bounds = [];
    let start = 0;

    for (let i = 0; i < text.length; i++) {
        if (text[i] === ' ') {
            bounds.push({ start, end: i - 1, space: i });
            start = i + 1;
        }
    }

    bounds.push({ start, end: text.length - 1, space: null });

    return bounds;
}

describe('resumePosition', () => {
    // "the quick brown fox" -> spans 4, 6, 6, 3 (kata + spasi; kata terakhir tanpa spasi)
    const text = 'the quick brown fox';
    const bounds = boundsFor(text);

    it('tidak memulihkan apa pun untuk attempt yang baru', () => {
        expect(resumePosition(bounds, text.length, 0)).toEqual({ consumed: 0, wordIndex: 0 });
    });

    it('memulihkan kata utuh dan berhenti di awal kata berikutnya', () => {
        // 10 dari 19 karakter = 52% -> "the quick " (4 + 6) pas, "brown " tidak muat.
        expect(resumePosition(bounds, text.length, 52)).toEqual({ consumed: 10, wordIndex: 2 });
    });

    it('tidak pernah memulihkan kata separuh', () => {
        // 37% dari 19 = ~7 karakter: jatuh di tengah "quick", jadi hanya "the " yang dihitung.
        expect(resumePosition(bounds, text.length, 37)).toEqual({ consumed: 4, wordIndex: 1 });
    });

    it('mengunci indeks kata terakhir saat progress 100%', () => {
        // Seluruh teks termakan; wordIndex di-clamp supaya wordBounds[i] tak pernah undefined.
        const { consumed, wordIndex } = resumePosition(bounds, text.length, 100);

        expect(consumed).toBe(text.length);
        expect(wordIndex).toBe(bounds.length - 1);
    });

    it('mengabaikan persen liar alih-alih melompat ke posisi mustahil', () => {
        expect(resumePosition(bounds, text.length, 250).consumed).toBe(text.length);
        expect(resumePosition(bounds, text.length, -40).consumed).toBe(0);
    });

    it('aman untuk teks kosong', () => {
        expect(resumePosition([], 0, 50)).toEqual({ consumed: 0, wordIndex: 0 });
    });
});
