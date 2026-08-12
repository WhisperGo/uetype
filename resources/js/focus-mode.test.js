// @vitest-environment happy-dom
//
// Per-file, not in vitest.config.js: this is the only suite here that touches the DOM, and the
// other three are pure arithmetic that runs faster without one.

import { describe, it, expect, beforeEach, afterEach } from 'vitest';
import { registerFocusMode, FOCUS_CLASS } from './focus-mode';

/**
 * Focus mode: sementara pemain mengetik, kerangka halaman menyingkir.
 *
 * Sinyalnya event `test-activity` yang sudah ada -- yang sama yang dipakai tombol chat untuk
 * menyembunyikan diri dan toast untuk MENAHAN dirinya. Modul ini tak menambah sinyal baru, ia
 * cuma menambah satu pendengar lagi: sebuah kelas di <body> yang CSS-nya menerjemahkan jadi
 * navbar & footer yang meredup.
 *
 * Kelas di body, bukan Alpine di tiap elemen, karena dua bagian yang harus bereaksi hidup di
 * layout dan salah satunya (footer) tak punya x-data sama sekali -- dan karena "kembali saat
 * kursor mendekat" adalah :hover, sesuatu yang CSS lakukan tanpa satu baris JS pun.
 */
function fireTestActivity(active) {
    window.dispatchEvent(new CustomEvent('test-activity', { detail: { active } }));
}

describe('focus mode', () => {
    beforeEach(() => {
        document.body.className = '';
        delete window.__focusModeRegistered;
        registerFocusMode();
    });

    afterEach(() => {
        document.body.className = '';
    });

    it('tidak menyalakan apa pun sebelum ada yang mengetik', () => {
        expect(document.body.classList.contains(FOCUS_CLASS)).toBe(false);
    });

    it('menyalakan focus mode saat sesi mengetik dimulai', () => {
        fireTestActivity(true);

        expect(document.body.classList.contains(FOCUS_CLASS)).toBe(true);
    });

    it('mematikannya lagi saat sesi selesai', () => {
        fireTestActivity(true);
        fireTestActivity(false);

        expect(document.body.classList.contains(FOCUS_CLASS)).toBe(false);
    });

    it('mematikannya saat berpindah halaman', () => {
        // Sesi halaman lama sudah berakhir bersama halamannya. Tanpa ini, meninggalkan
        // /typing di tengah tes akan mendaratkan pemain di halaman berikutnya dengan navbar
        // yang tak terlihat dan tak ada cara jelas mengembalikannya -- persis pola bug yang
        // sudah pernah kena di proyek ini (link keluar war yang tak bisa diklik).
        fireTestActivity(true);
        document.dispatchEvent(new Event('livewire:navigated'));

        expect(document.body.classList.contains(FOCUS_CLASS)).toBe(false);
    });

    it('memperlakukan event tanpa detail sebagai selesai, bukan mulai', () => {
        fireTestActivity(true);
        window.dispatchEvent(new CustomEvent('test-activity'));

        // Fail-safe ke arah TERLIHAT. Sebuah event cacat yang menyembunyikan navbar adalah
        // kerangka halaman yang hilang tanpa ada yang mengetik; kebalikannya cuma sedikit
        // gangguan. Kalau salah, salah ke arah yang bisa dilihat pemain.
        expect(document.body.classList.contains(FOCUS_CLASS)).toBe(false);
    });

    it('hanya mendaftar sekali walau dipanggil berulang', () => {
        registerFocusMode();
        registerFocusMode();

        fireTestActivity(true);
        fireTestActivity(false);

        expect(document.body.classList.contains(FOCUS_CLASS)).toBe(false);
    });
});
