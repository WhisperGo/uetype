<?php

use App\Livewire\Settings;
use App\Models\User;
use Illuminate\Support\Facades\Auth;

/*
|--------------------------------------------------------------------------
| "Tetap masuk sampai sign out".
|
| Kolom remember_token sudah ada sejak migrasi pertama tapi TAK PERNAH diisi:
| Auth::login() dipanggil tanpa argumen kedua di ketiga jalur login. Akibatnya
| cookie sesi mati saat browser ditutup dan server membuangnya setelah idle,
| tanpa apa pun yang bisa memulihkan login -- inilah keluhan "kok sering
| ter-logout" yang dilaporkan pemain.
|--------------------------------------------------------------------------
*/

/** Nama cookie recaller milik guard web (remember_web_<hash>). */
function recallerCookie(): string
{
    return Auth::guard('web')->getRecallerName();
}

it('menyalakan remember-me saat user lama masuk lewat Google', function () {
    $user = User::factory()->create([
        'google_id' => 'g-12345',
        'email' => 'pemain@gmail.com',
        // Faktori mengisinya; dikosongkan supaya buktinya bersih.
        'remember_token' => null,
    ]);
    fakeGoogleUser();

    $response = $this->get('/auth/google/callback')->assertRedirect('/typing');

    expect($user->fresh()->remember_token)->not->toBeNull();
    $response->assertCookie(recallerCookie());
});

it('menyalakan remember-me saat user baru selesai memilih username', function () {
    fakeGoogleUser();
    $this->get('/auth/google/callback');

    $response = $this->post('/auth/google/username', ['username' => 'pemainbaru']);

    expect(User::where('username', 'pemainbaru')->firstOrFail()->remember_token)->not->toBeNull();
    $response->assertCookie(recallerCookie());
});

it('membatalkan login yang diingat saat user sign out', function () {
    $user = User::factory()->create();
    $sebelum = $user->remember_token;

    $this->actingAs($user)->post('/logout')->assertRedirect('/');

    // Dua bagian, dan yang ini yang benar-benar membuktikannya: SessionGuard::logout()
    // memanggil cycleRememberToken(), jadi recaller lama tak lagi cocok dengan barisnya --
    // terlihat di DB, bukan cuma di pipa cookie.
    expect($user->fresh()->remember_token)->not->toBe($sebelum);
    $this->assertGuest();
});

it('tak menyisakan recaller yatim setelah akun dihapus', function () {
    $user = User::factory()->create();
    $id = $user->id;

    $this->actingAs($user);
    Livewire\Livewire::actingAs($user)
        ->test(Settings::class)
        ->set('confirmUsername', $user->username)
        ->call('deleteAccount');

    // retrieveByToken mencocokkan id + remember_token; tanpa barisnya, tak ada yang resolve.
    expect(User::find($id))->toBeNull();
});

it('memberi getAuthPassword string kosong, bukan null', function () {
    // SessionGuard::queueRecallerCookie() menyuapkan nilai ini ke hash_hmac(), dan $data
    // null sudah deprecated di PHP 8.1+ -- satu deprecation tiap sign-in. Nilainya tak
    // pernah dibaca ulang: recaller divalidasi dari id + remember_token saja.
    expect((new User)->getAuthPassword())->toBe('');
});
