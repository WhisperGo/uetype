<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;
use Laravel\Socialite\Two\User as SocialiteUser;

/**
 * Setelah jalur password Breeze dihapus, GoogleAuthController adalah SATU-SATUNYA
 * kode login di aplikasi -- sebelumnya tak punya test sama sekali.
 */

/** Palsukan balasan Google supaya callback bisa diuji tanpa keluar jaringan. */
function fakeGoogleUser(string $id = 'g-12345', string $email = 'pemain@gmail.com'): SocialiteUser
{
    $googleUser = new SocialiteUser;
    $googleUser->id = $id;
    $googleUser->email = $email;
    $googleUser->avatar = 'https://lh3.googleusercontent.com/a/foto';

    $provider = Mockery::mock(GoogleProvider::class);
    $provider->shouldReceive('user')->andReturn($googleUser);

    Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

    return $googleUser;
}

describe('session fixation', function () {
    /**
     * Auth::login() TIDAK mengganti session ID. Tanpa regenerate, session ID yang
     * dipegang sejak sebelum login tetap valid sesudahnya -- penyerang yang bisa
     * menanamkan session ID ke browser korban (mesin bersama/lab) ikut masuk
     * sebagai korban. Breeze memanggil regenerate() eksplisit; jalur Google lupa.
     */
    it('mengganti session id saat user lama login', function () {
        User::factory()->create(['google_id' => 'g-12345', 'email' => 'pemain@gmail.com']);
        fakeGoogleUser();

        $this->get('/login');
        $sebelum = session()->getId();

        $this->get('/auth/google/callback')->assertRedirect('/typing');

        expect(session()->getId())->not->toBe($sebelum);
        $this->assertAuthenticated();
    });

    it('mengganti session id saat user baru menyelesaikan pemilihan username', function () {
        fakeGoogleUser();

        $this->get('/auth/google/callback');
        $sebelum = session()->getId();

        $this->post('/auth/google/username', ['username' => 'pemainbaru'])
            ->assertRedirect('/typing');

        expect(session()->getId())->not->toBe($sebelum);
        $this->assertAuthenticated();
    });
});

describe('callback google', function () {
    it('menitipkan data google ke session tepat satu kali untuk user baru', function () {
        fakeGoogleUser();

        $this->get('/auth/google/callback')
            ->assertRedirect(route('auth.google.choose-username'));

        // Dulu blok session()->put() ditulis dua kali persis berturut-turut.
        expect(session('google_register_data'))->toBe([
            'email' => 'pemain@gmail.com',
            'google_id' => 'g-12345',
            'avatar' => 'https://lh3.googleusercontent.com/a/foto',
        ]);

        $this->assertGuest();
    });

    it('mencatat log saat OAuth gagal, bukan menelannya diam-diam', function () {
        Log::spy();

        $provider = Mockery::mock(GoogleProvider::class);
        $provider->shouldReceive('user')->andThrow(new RuntimeException('invalid state'));
        Socialite::shouldReceive('driver')->with('google')->andReturn($provider);

        $this->get('/auth/google/callback')->assertRedirect(route('login'));

        Log::shouldHaveReceived('warning')->once();
    });

    it('menyambungkan google_id ke akun lama yang emailnya sama', function () {
        $user = User::factory()->create(['google_id' => null, 'email' => 'pemain@gmail.com']);
        fakeGoogleUser();

        $this->get('/auth/google/callback')->assertRedirect('/typing');

        expect($user->fresh()->google_id)->toBe('g-12345');
    });
});

describe('pemilihan username', function () {
    // GoogleAuthController dulu me-redirect ke '/register' -- route yang ikut
    // terhapus bersama Breeze. Tanpa perbaikan, alur ini 404 di tengah jalan.
    it('mengembalikan ke login bila tak ada data google di session', function () {
        $this->get('/auth/google/username')->assertRedirect(route('login'));
        $this->post('/auth/google/username', ['username' => 'x'])->assertRedirect(route('login'));
    });

    it('membuat user baru tanpa kolom password', function () {
        fakeGoogleUser();
        $this->get('/auth/google/callback');

        $this->post('/auth/google/username', ['username' => 'pemainbaru']);

        $user = User::where('username', 'pemainbaru')->firstOrFail();

        expect($user->email)->toBe('pemain@gmail.com')
            ->and($user->google_id)->toBe('g-12345')
            ->and(array_key_exists('password', $user->getAttributes()))->toBeFalse();
    });

    it('menolak username yang sudah dipakai', function () {
        User::factory()->create(['username' => 'terpakai']);
        fakeGoogleUser();
        $this->get('/auth/google/callback');

        $this->post('/auth/google/username', ['username' => 'terpakai'])
            ->assertSessionHasErrors('username');

        $this->assertGuest();
    });
});
