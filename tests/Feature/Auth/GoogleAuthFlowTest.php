<?php

use App\Models\User;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GoogleProvider;

/**
 * Setelah jalur password Breeze dihapus, GoogleAuthController adalah SATU-SATUNYA
 * kode login di aplikasi -- sebelumnya tak punya test sama sekali.
 */

// fakeGoogleUser() tinggal di tests/Pest.php: dua berkas test memakainya, dan fungsi yang
// dideklarasikan di sebuah berkas test baru global saat suite dijalankan penuh.

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

// ---- JALAN KELUAR & TUJUAN SETELAH DAFTAR ----

it('mengantar user baru ke halaman yang tadi ingin dibuka, bukan selalu /typing', function () {
    // Middleware auth menyimpan url.intended saat tamu ditolak dari /clans.
    $this->get(route('clans.index'))->assertRedirect(route('login'));

    fakeGoogleUser();
    $this->get('/auth/google/callback');

    $this->post('/auth/google/username', ['username' => 'pemainbaru'])
        ->assertRedirect(route('clans.index'));
});

it('memakai tujuan yang sama untuk akun yang ternyata sudah ada', function () {
    // Cabang "sudah ada" di storeUsername dulu juga hardcoded ke /typing.
    $this->get(route('clans.index'))->assertRedirect(route('login'));

    fakeGoogleUser();
    $this->get('/auth/google/callback');

    // Akun dengan google_id yang sama muncul di antara callback dan submit.
    User::factory()->create(['google_id' => 'g-12345', 'email' => 'pemain@gmail.com']);

    $this->post('/auth/google/username', ['username' => 'namalain'])
        ->assertRedirect(route('clans.index'));
});

it('membersihkan data registrasi yang ditinggalkan saat tamu kembali ke login', function () {
    fakeGoogleUser();
    $this->get('/auth/google/callback');

    expect(session('google_register_data'))->not->toBeNull();

    // Cancel di halaman username mengembalikan tamu ke /login -- tanpa pembersihan,
    // payload Google basi tetap tertinggal dan formulirnya masih bisa dibuka lagi.
    $this->get('/login')->assertOk();

    expect(session('google_register_data'))->toBeNull();
    $this->get('/auth/google/username')->assertRedirect(route('login'));
});

it('memberi jalan keluar berlabel di halaman pilih username', function () {
    fakeGoogleUser();
    $this->get('/auth/google/callback');

    $html = $this->get('/auth/google/username')->assertOk()->getContent();

    expect(guestBackHref($html))->toBe(route('login'));
});

it('memasangkan setiap focus:outline-none dengan cincin fokus yang terlihat', function () {
    // Tautan Cancel-nya dulu mematikan outline tanpa mengganti apa pun: pengguna keyboard
    // kehilangan jejak fokusnya sama sekali.
    $blade = file_get_contents(resource_path('views/auth/google-username.blade.php'));

    preg_match_all('/class="([^"]*focus:outline-none[^"]*)"/', $blade, $m);

    expect($m[1])->not->toBeEmpty();

    foreach ($m[1] as $classes) {
        expect($classes)->toContain('focus-visible:ring');
    }
});

it('menampilkan alasan saat login Google gagal', function () {
    // Pesannya sudah di-flash sejak controller ini ditulis, tapi login.blade.php cuma
    // merender session('status') -- jadi kegagalan OAuth memantulkan tamu ke halaman
    // KOSONG tanpa satu pun petunjuk.
    Socialite::shouldReceive('driver')->with('google')->andThrow(new Exception('gagal'));

    $this->get('/auth/google/callback')->assertRedirect(route('login'));

    $this->get('/login')->assertOk()->assertSee(__('auth.google_failed'));
});
