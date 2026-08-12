<?php

use App\Livewire\Chat;
use App\Models\Message;
use App\Models\User;
use Livewire\Livewire;

/**
 * Chat punya DUA pintu kirim: komponen Livewire, dan endpoint POST /chat/send yang sengaja
 * berada di luar antrean Livewire supaya pesan beruntun tak saling menunggu.
 *
 * Dua pintu boleh; dua salinan aturan otorisasi tidak. Dulu controller memuat versinya sendiri
 * dari "teman yang sudah diterima", "clan aktif saya", dan kedua pemeriksa target balasan --
 * lengkap dengan komentar yang menjanjikan keduanya "sama persis", janji yang tak dijaga apa
 * pun. Arah kegagalannya senyap: perketat aturan di komponen, endpoint tetap memakai gembok
 * lama, dan tak ada yang merah.
 *
 * Test ini menjalankan skenario yang SAMA lewat kedua pintu dan menuntut hasil yang sama.
 * Kalau nanti aturannya berubah di satu sisi saja, berkas inilah yang berubah warna.
 */
// befriend() & userInClan() datang dari tests/Pest.php.

it('refuses a DM to a non-friend through both doors', function () {
    $me = User::factory()->create();
    $stranger = User::factory()->create();

    // Pintu 1: endpoint.
    $this->actingAs($me)->postJson(route('chat.send'), [
        'mode' => 'dm', 'body' => 'halo', 'with' => $stranger->username,
    ])->assertStatus(403);

    // Pintu 2: komponen.
    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $stranger->username)
        ->set('body', 'halo')
        ->call('sendMessage');

    expect(Message::where('recipient_id', $stranger->id)->count())->toBe(0);
});

it('refuses a reply that points outside the conversation through both doors', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create();
    befriend($me, $friend);

    // Percakapan orang lain, yang sama sekali tak melibatkan $me.
    $outsiderA = User::factory()->create();
    $outsiderB = User::factory()->create();
    $foreign = Message::create([
        'sender_id' => $outsiderA->id, 'recipient_id' => $outsiderB->id, 'body' => 'rahasia',
    ]);

    $this->actingAs($me)->postJson(route('chat.send'), [
        'mode' => 'dm', 'body' => 'balas', 'with' => $friend->username, 'reply_to_id' => $foreign->id,
    ])->assertOk();

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('replyingToId', $foreign->id)
        ->set('body', 'balas juga')
        ->call('sendMessage');

    // Pesannya tetap terkirim -- yang ditolak hanyalah kutipannya, karena reply_to_id yang
    // menunjuk keluar percakapan akan membocorkan isi pesan orang lain lewat preview balasan.
    $sent = Message::where('sender_id', $me->id)->get();

    expect($sent)->toHaveCount(2)
        ->and($sent->pluck('reply_to_id')->filter()->all())->toBe([]);
});

it('accepts a reply that belongs to the conversation through both doors', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create();
    befriend($me, $friend);

    $theirs = Message::create([
        'sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'pesan asli',
    ]);

    $this->actingAs($me)->postJson(route('chat.send'), [
        'mode' => 'dm', 'body' => 'lewat endpoint', 'with' => $friend->username, 'reply_to_id' => $theirs->id,
    ])->assertOk();

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('replyingToId', $theirs->id)
        ->set('body', 'lewat komponen')
        ->call('sendMessage');

    expect(Message::where('sender_id', $me->id)->pluck('reply_to_id')->all())
        ->toBe([$theirs->id, $theirs->id]);
});

it('refuses to quote a message its sender retracted, through both doors', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create();
    befriend($me, $friend);

    $retracted = Message::create([
        'sender_id' => $friend->id, 'recipient_id' => $me->id, 'body' => 'salah kirim',
        'deleted_for_everyone_at' => now(),
    ]);

    $this->actingAs($me)->postJson(route('chat.send'), [
        'mode' => 'dm', 'body' => 'balas', 'with' => $friend->username, 'reply_to_id' => $retracted->id,
    ])->assertOk();

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('replyingToId', $retracted->id)
        ->set('body', 'balas juga')
        ->call('sendMessage');

    // Aturan ini dulu hanya ada di startReply() -- pintu UI -- jadi permintaan buatan tangan
    // masih bisa mengutip badan pesan yang sudah ditarik pengirimnya. Sekarang ia berada di
    // jalur yang benar-benar dilewati setiap balasan.
    expect(Message::where('sender_id', $me->id)->pluck('reply_to_id')->filter()->all())->toBe([]);
});

it('refuses clan chat for someone with no clan through both doors', function () {
    $loner = User::factory()->create();
    [, $clan] = userInClan('Somebody Elses Clan');

    $this->actingAs($loner)->postJson(route('chat.send'), [
        'mode' => 'clan', 'body' => 'halo',
    ])->assertStatus(403);

    Livewire::actingAs($loner)->test(Chat::class)
        ->call('openClanChat')
        ->set('body', 'halo')
        ->call('sendMessage');

    expect(Message::where('clan_id', $clan->id)->count())->toBe(0);
});
