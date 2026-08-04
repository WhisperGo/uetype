<?php

use App\Models\User;

/**
 * Profil publik dan profil sendiri berbagi SATU view, jadi batas antara keduanya harus berada di
 * data, bukan di `@if` di dalam markup.
 *
 * Sebelumnya view menerima model User utuh dan menahan alamat email hanya dengan
 * `@if(! $isPublic)`. Itu benar, tapi satu-satunya yang melindungi field privat adalah sebuah
 * kondisi di lapisan presentasi -- dan sunting berikutnya yang menambahkan sesuatu di LUAR
 * kondisi itu tak akan menyalakan apa pun.
 */
it('never renders another user email on their public profile', function () {
    $viewer = User::factory()->create();
    $subject = User::factory()->create(['email' => 'rahasia@uetype.test']);

    $response = $this->actingAs($viewer)->get(route('profile.show', $subject->username));

    $response->assertOk();
    $response->assertSee($subject->username);
    $response->assertDontSee('rahasia@uetype.test');
});

it('still shows your own email on your own profile', function () {
    $me = User::factory()->create(['email' => 'punyaku@uetype.test']);

    $this->actingAs($me)->get(route('profile.me'))
        ->assertOk()
        ->assertSee('punyaku@uetype.test');
});

it('shows your own email when you open your own public profile url', function () {
    // show() mengalihkan ke me() kalau subjeknya adalah penonton sendiri, jadi cabang ini harus
    // tetap memperlakukannya sebagai profil privat.
    $me = User::factory()->create(['email' => 'sendiri@uetype.test']);

    $this->actingAs($me)->get(route('profile.show', $me->username))
        ->assertOk()
        ->assertSee('sendiri@uetype.test');
});
