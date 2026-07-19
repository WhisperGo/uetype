<?php

use App\Models\User;

/**
 * Autentikasi UeType adalah Google-only. Tabel `users` memang TIDAK punya kolom
 * `password`, jadi seluruh jalur password bawaan Breeze pernah ada sebagai kode
 * mati -- tapi route-nya tetap hidup: POST /register membuat akun tanpa kredensial
 * DAN me-login-kan pemanggilnya, tanpa throttle. Test ini mengunci jalur itu tetap
 * tertutup supaya tidak pernah kembali lewat scaffolding yang di-generate ulang.
 */
describe('jalur password Breeze tertutup', function () {
    // Pembuatan akun HANYA boleh lewat Google. Ini yang dulu bisa dieksploitasi.
    it('menolak POST /register', function () {
        $this->post('/register', [
            'username' => 'penyusup',
            'email' => 'penyusup@example.com',
            'password' => 'Password123!',
            'password_confirmation' => 'Password123!',
        ])->assertNotFound();

        expect(User::where('username', 'penyusup')->exists())->toBeFalse();
        $this->assertGuest();
    });

    // 405, bukan 404: GET /login tetap ada (halaman Google), yang hilang hanya
    // method POST-nya. Yang penting tak ada satu pun kredensial yang diproses.
    it('menolak POST /login', function () {
        $this->post('/login', ['username' => 'siapa', 'password' => 'apa'])
            ->assertMethodNotAllowed();

        $this->assertGuest();
    });

    // Dulu benar-benar mengirim email reset untuk aplikasi yang tak punya password.
    it('menolak permintaan reset password', function () {
        $this->post('/forgot-password', ['email' => 'a@example.com'])->assertNotFound();
        $this->post('/reset-password', ['token' => 'x', 'email' => 'a@example.com'])->assertNotFound();
    });

    it('menolak ganti & konfirmasi password', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->put('/password', [])->assertNotFound();
        $this->actingAs($user)->get('/confirm-password')->assertNotFound();
    });

    // Tidak ada kolom email_verified_at & User tak meng-implement MustVerifyEmail:
    // halaman ini meminta verifikasi lewat mekanisme yang tak pernah ada.
    it('menolak halaman verifikasi email', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/verify-email')->assertNotFound();
    });

    // Rantai redirect ganda (/dashboard -> / -> /typing) dengan middleware
    // `verified` yang inert karena User bukan MustVerifyEmail.
    it('menolak /dashboard', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->get('/dashboard')->assertNotFound();
    });
});

describe('jalur Google yang tersisa', function () {
    it('menyajikan halaman login berisi tombol Google', function () {
        $this->get('/login')
            ->assertOk()
            ->assertSee(route('auth.google'), escape: false);
    });

    it('mengeluarkan user lewat POST /logout', function () {
        $user = User::factory()->create();

        $this->actingAs($user)->post('/logout')->assertRedirect('/');

        $this->assertGuest();
    });

    /**
     * Middleware `auth` Laravel me-redirect ke route BERNAMA `login`. Nama itu harus
     * bertahan setelah routes/auth.php dihapus -- kalau tidak, setiap halaman
     * terproteksi melempar RouteNotFoundException untuk tamu.
     */
    it('mempertahankan nama route login untuk redirect middleware auth', function () {
        $this->get(route('settings'))->assertRedirect(route('login'));
    });
});
