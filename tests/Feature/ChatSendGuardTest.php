<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Livewire\Chat;
use App\Livewire\Concerns\GuardsChatAccess;
use App\Models\ClanMember;
use App\Models\Message;
use App\Models\User;
use Livewire\Component;
use Livewire\Livewire;

/**
 * Gerbang keamanan di GuardsChatAccess harus menjaga DIRINYA SENDIRI, bukan menitipkannya ke
 * pemanggil.
 *
 * sendDmMessage() memanggil isAcceptedFriend() di dalam dirinya sendiri; sendClanMessageAs()
 * tidak memeriksa apa pun dan bergantung sepenuhnya pada satu-satunya pemanggilnya mengoper
 * $this->myClan->id. Itu benar hari ini, tapi asimetri di dalam trait yang namanya "Guards..."
 * adalah persis tempat pemanggil berikutnya akan mengira gerbangnya sudah ada.
 *
 * Komponen di bawah adalah pemanggil berikutnya itu, dibuat sengaja: ia mengoper clan yang bukan
 * miliknya, seperti kode yang ditulis orang lain enam bulan dari sekarang.
 */
class UnguardedClanSender extends Component
{
    use GuardsChatAccess;

    public int $targetClanId = 0;

    public function send(string $body): void
    {
        $this->sendClanMessageAs($this->targetClanId, $body);
    }

    public function render()
    {
        return '<div></div>';
    }
}

it('refuses to post into a clan the sender does not belong to', function () {
    [, $theirClan] = userInClan('Fortress');
    $outsider = User::factory()->create();

    Livewire::actingAs($outsider)->test(UnguardedClanSender::class)
        ->set('targetClanId', $theirClan->id)
        ->call('send', 'aku menyusup');

    expect(Message::where('clan_id', $theirClan->id)->count())->toBe(0);
});

it('refuses to post into a clan the sender has only a pending request to', function () {
    [$leader, $theirClan] = userInClan('Gatekeepers');
    $applicant = User::factory()->create();

    // Permintaan bergabung yang belum disetujui bukan keanggotaan.
    ClanMember::create([
        'clan_id' => $theirClan->id,
        'user_id' => $applicant->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Pending,
    ]);

    Livewire::actingAs($applicant)->test(UnguardedClanSender::class)
        ->set('targetClanId', $theirClan->id)
        ->call('send', 'halo semua');

    expect(Message::where('clan_id', $theirClan->id)->count())->toBe(0);
    expect($leader->id)->not->toBe($applicant->id);
});

it('still lets an active member post into their own clan', function () {
    [$leader, $clan] = userInClan('Homeground');

    Livewire::actingAs($leader)->test(UnguardedClanSender::class)
        ->set('targetClanId', $clan->id)
        ->call('send', 'halo tim');

    expect(Message::where('clan_id', $clan->id)->count())->toBe(1);
});

/**
 * Batas panjang pesan punya SATU definisi.
 *
 * 2000 dulu muncul sebagai literal di tiga tempat -- dua di ManagesChatConversation, satu di
 * aturan validasi ChatController. Batas yang sama dengan tiga sumber adalah batas yang bisa
 * bergeser di dua tempat tanpa ada yang tahu.
 */
it('enforces the same body limit through both send doors', function () {
    $me = User::factory()->create();
    $friend = User::factory()->create();
    befriend($me, $friend);

    $tooLong = str_repeat('a', Message::MAX_BODY_LENGTH + 1);

    $this->actingAs($me)->postJson(route('chat.send'), [
        'mode' => 'dm', 'body' => $tooLong, 'with' => $friend->username,
    ])->assertStatus(422);

    Livewire::actingAs($me)->test(Chat::class)
        ->call('openDm', $friend->username)
        ->set('body', $tooLong)
        ->call('sendMessage');

    expect(Message::where('recipient_id', $friend->id)->count())->toBe(0);

    // Dan tepat di batasnya keduanya menerima.
    $exact = str_repeat('b', Message::MAX_BODY_LENGTH);

    $this->actingAs($me)->postJson(route('chat.send'), [
        'mode' => 'dm', 'body' => $exact, 'with' => $friend->username,
    ])->assertOk();

    expect(Message::where('recipient_id', $friend->id)->count())->toBe(1);
});
