<?php

use App\Livewire\ChatOverlay;
use App\Models\Friendship;
use App\Models\Message;
use App\Models\User;
use Livewire\Livewire;

/**
 * Chat punya DUA varian yang hidup bersamaan di DOM saat /chat dibuka: halaman
 * penuh dan overlay drawer. Keduanya dulu menyalin ~145 baris markup pesan dan
 * ~141 baris JS yang identik.
 *
 * Penyatuannya hanya aman kalau dua hal ini tetap dijaga, dan itulah yang
 * dikunci di sini:
 *
 *   1. NAMESPACE TETAP TERPISAH. id, wire:key, nama fungsi global, dan nama
 *      event tak boleh ikut disatukan -- dua komponen di satu halaman akan
 *      saling menimpa DOM satu sama lain.
 *   2. window.__chatOverlayState HANYA DITULIS OVERLAY. toasts.js membacanya
 *      untuk memutuskan kapan toast disupresi; kalau halaman penuh ikut
 *      menulisnya, toast tertekan di halaman yang salah.
 */
function chatPageSource(): string
{
    return file_get_contents(resource_path('views/livewire/chat.blade.php'));
}

function chatOverlaySource(): string
{
    return file_get_contents(resource_path('views/livewire/chat-overlay.blade.php'));
}

function chatRuntimeSource(): string
{
    return file_get_contents(resource_path('js/chat-runtime.js'));
}

it('shares one message partial between the page and the overlay', function () {
    expect(resource_path('views/components/chat/message.blade.php'))->toBeFile();

    // Keduanya memanggil partial yang sama, dengan ukuran yang berbeda.
    expect(chatPageSource())->toContain('<x-chat.message')
        ->and(chatOverlaySource())->toContain('<x-chat.message');
});

it('shares the clear-chat modal, reply preview, and composer', function () {
    foreach (['clear-modal', 'reply-preview', 'composer'] as $partial) {
        expect(resource_path("views/components/chat/{$partial}.blade.php"))->toBeFile();
        expect(chatPageSource())->toContain("<x-chat.{$partial}")
            ->and(chatOverlaySource())->toContain("<x-chat.{$partial}");
    }
});

it('keeps the two chat variants in separate DOM namespaces', function () {
    $page = chatPageSource();
    $overlay = chatOverlaySource();

    // Container id berbeda -> auto-scroll & penempatan menu aksi tak salah sasaran.
    expect($page)->toContain('containerId="chat-messages"')
        ->and($overlay)->toContain('containerId="overlay-chat-messages"');

    // Prefix wire:key berbeda -> morph Livewire tak menukar bubble antar komponen.
    expect($page)->toContain('keyPrefix="msg"')
        ->and($overlay)->toContain('keyPrefix="overlay-msg"');

    // Event scroll berbeda -> menu aksi di satu varian tak ditutup oleh scroll
    // di varian lain.
    expect($page)->toContain('chat-scrolled')
        ->and($overlay)->toContain('chat-overlay-scrolled');
});

it('writes the overlay state flag only from the overlay', function () {
    // Ini yang dibaca toasts.js. Kalau halaman penuh ikut menulisnya, toast DM
    // akan tersupresi walau drawer-nya tertutup.
    expect(chatOverlaySource())->toContain('window.__chatOverlayState = {');

    // Yang dilarang adalah MENULIS, bukan menyebut: chat-runtime.js menjelaskan
    // di komentarnya kenapa flag ini sengaja tak ikut pindah ke sana, dan
    // larangan berbasis substring polos akan menjaring komentar itu.
    $write = '/__chatOverlayState(\.\w+)?\s*=[^=]/';

    expect(chatPageSource())->not->toMatch($write)
        ->and(chatRuntimeSource())->not->toMatch($write);
});

it('moves the chat javascript out of blade and into modules', function () {
    foreach (['chat-runtime.js', 'chat-dock.js'] as $module) {
        expect(resource_path("js/{$module}"))->toBeFile();
    }

    $appJs = file_get_contents(resource_path('js/app.js'));
    expect($appJs)->toContain('chat-runtime')
        ->and($appJs)->toContain('chat-dock');

    // Logika dock (drag + clamp viewport) tak lagi ditulis inline di Blade.
    expect(chatOverlaySource())->not->toContain('pxToAnchor')
        ->and(chatOverlaySource())->not->toContain('startDrag(e)');

    // Bodi runtime (fetch + bubble optimistic) juga tak lagi disalin di Blade.
    expect(chatPageSource())->not->toContain('__chatPendingSends')
        ->and(chatOverlaySource())->not->toContain('__chatOverlayPendingSends');
});

it('gives each variant its own send function and pending counter', function () {
    // Dua komponen mengirim bersamaan; counter bersama akan membuat salah satu
    // membuang bubble optimistic milik yang lain.
    $runtime = chatRuntimeSource();

    expect($runtime)->toContain('chatSend')
        ->and($runtime)->toContain('chatOverlaySend');
});

it('still renders the chat page with both variants mounted', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create();
    Friendship::create([
        'requester_id' => $me->id,
        'addressee_id' => $friend->id,
        'status' => 'accepted',
    ]);

    Message::create(['sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'hai']);

    // Halaman penuh dibuka langsung ke DM -> container-nya dirender...
    $html = $this->actingAs($me)
        ->get(route('chat.index', ['mode' => 'dm', 'with' => $friend->username]))
        ->assertOk()
        ->assertSee('id="chat-messages"', false)
        ->getContent();

    // ...dan overlay ikut ter-mount di halaman yang sama (masih di picker-nya,
    // jadi daftar pesannya belum dirender).
    expect($html)->toContain('chatOverlayDock(')
        ->and($html)->toContain('window.__chatOverlayState');

    // Begitu drawer-nya juga dibuka ke sebuah thread, kedua daftar pesan hidup
    // bersamaan -- dan di sanalah pemisahan namespace jadi wajib.
    Livewire::actingAs($me)->test(ChatOverlay::class)
        ->call('openDm', $friend->username)
        ->assertSee('id="overlay-chat-messages"', false)
        ->assertSee('wire:key="overlay-msg-', false);
});
