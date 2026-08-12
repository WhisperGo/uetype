<?php

use App\Livewire\Settings;
use App\Models\User;
use App\Support\UsernameRules;
use Illuminate\Validation\Rules\Unique;
use Livewire\Livewire;

/**
 * Aturan validasi username identik di dua tempat berbeda jenis: Settings
 * (Livewire) dan GoogleAuthController (controller). FormRequest tak pas untuk
 * sisi Livewire, jadi satu sumbernya adalah method statik ini.
 *
 * Yang dikunci di sini: bentuk aturannya benar, dan perbedaan satu-satunya antara
 * kedua pemanggil -- unique ignore-self di Settings vs tanpa ignore di register --
 * ditangani lewat argumen, bukan dua salinan yang bisa menyimpang.
 */
it('returns the canonical username rules', function () {
    $rules = UsernameRules::rules();

    expect($rules)->toContain('required', 'string', 'alpha_dash', 'min:3', 'max:20');

    // Rule unique selalu ada; tanpa argumen berarti tanpa ignore.
    $unique = collect($rules)->first(fn ($r) => $r instanceof Unique);
    expect($unique)->not->toBeNull()
        ->and((string) $unique)->not->toContain('ignore');
});

it('scopes the unique rule to ignore a given id when asked', function () {
    $unique = collect(UsernameRules::rules(5))->first(fn ($r) => $r instanceof Unique);

    // Settings meneruskan id user agar username miliknya sendiri tak dianggap bentrok.
    expect((string) $unique)->toContain('"5"');
});

it('lets settings keep the user\'s own username but rejects another user\'s', function () {
    $me = User::factory()->create(['username' => 'akuu']);
    $taken = User::factory()->create(['username' => 'kamuu']);

    // Ignore-self benar-benar terpasang: menyimpan ulang username sendiri lolos.
    Livewire::actingAs($me)->test(Settings::class)
        ->set('username', 'akuu')->call('saveUsername')
        ->assertHasNoErrors('username');

    // Username milik user lain tetap ditolak.
    Livewire::actingAs($me)->test(Settings::class)
        ->set('username', 'kamuu')->call('saveUsername')
        ->assertHasErrors('username');
});
