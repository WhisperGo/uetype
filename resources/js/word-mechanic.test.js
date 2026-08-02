import { describe, it, expect } from 'vitest';
import { evaluateTyping } from './word-mechanic';

describe('evaluateTyping', () => {
    it('huruf salah tetap masuk ke field dan ditandai error', () => {
        // Target "then", pemain sudah mengetik "t" (prevTypedLength 1) lalu menekan "x".
        expect(evaluateTyping('then', 'tx', 1)).toEqual({
            hasError: true,
            keystrokes: 1,
            mistakes: 1,
        });
    });

    it('menerima huruf yang benar tanpa menandai error', () => {
        expect(evaluateTyping('then', 'th', 1)).toEqual({
            hasError: false,
            keystrokes: 1,
            mistakes: 0,
        });
    });

    it('tidak menghitung backspace sebagai keystroke baru', () => {
        // "tx" (2 karakter) dihapus jadi "t": field memendek, bukan ketikan baru.
        expect(evaluateTyping('then', 't', 2)).toEqual({
            hasError: false,
            keystrokes: 0,
            mistakes: 0,
        });
    });

    it('memulihkan keadaan setelah kesalahan diperbaiki', () => {
        // Inilah keluhan aslinya: salah -> backspace -> ketik benar -> layar bersih lagi.
        expect(evaluateTyping('then', 'th', 3).hasError).toBe(false);
    });

    it('tidak menganggap field kosong sebagai kesalahan', () => {
        expect(evaluateTyping('then', '', 0).hasError).toBe(false);
    });

});