<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Events\ClanMessageSent;
use App\Events\ClanUpdated;
use App\Events\DirectMessageSent;
use App\Events\FriendshipUpdated;
use App\Events\MessageDeleted;
use App\Events\MessageEdited;
use App\Events\PresenceUpdated;
use App\Events\RaceProgressUpdated;
use App\Events\RoomInvitationSent;
use App\Events\RoomMessageSent;
use App\Events\RoomUpdated;
use App\Models\ClanMember;
use App\Models\Message;
use App\Models\User;
use App\Support\ChannelAccess;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Support\Facades\Broadcast;

/**
 * Channel yang di-key dengan ID BERURUTAN harus privat.
 *
 * Reverb menyajikan channel publik ke siapa pun yang punya app key -- dan app key memang publik,
 * ia harus ada di bundle JS. Untuk `room.{code}`/`race.{code}` itu tak apa: kodenya
 * `strtoupper(Str::random(6))` dan tak bisa ditebak. Untuk `chat.{userId}`, `clan-chat.{clanId}`,
 * `friends.{userId}` dan `clan.{userId}` argumen itu tidak berlaku sama sekali -- ID-nya berurutan
 * dari 1, jadi `Echo.channel('chat.5')` cukup untuk membaca seluruh DM masuk milik user #5.
 *
 * Dua hal yang membuatnya lebih buruk dari sekadar "metadata bocor":
 *
 *  1. `body` disimpan terenkripsi (cast 'encrypted'), tapi cast Laravel MENDEKRIPSI saat atribut
 *     diakses -- jadi payload-nya plaintext. Enkripsi at-rest membayar seluruh biayanya tanpa
 *     melindungi apa pun di jalur ini.
 *  2. `RoomInvitationSent` membawa roomCode di `friends.{userId}`. Kode itulah yang melindungi
 *     `room.{code}`/`race.{code}`, jadi channel publik ini membocorkan KREDENSIAL channel lain.
 */
function subscribes(object $event): string
{
    $channels = $event->broadcastOn();

    return $channels[0]::class;
}

it('keeps every identity-keyed channel private', function () {
    $sender = User::factory()->create();
    $recipient = User::factory()->create();

    $dm = Message::create([
        'sender_id' => $sender->id,
        'recipient_id' => $recipient->id,
        'body' => 'rahasia',
    ]);

    [, $clan] = userInClan('Channel Clan');

    $clanMessage = Message::create([
        'sender_id' => $sender->id,
        'clan_id' => $clan->id,
        'body' => 'rahasia clan',
    ]);

    expect(subscribes(new DirectMessageSent($dm->load('sender'))))->toBe(PrivateChannel::class);
    expect(subscribes(new ClanMessageSent($clanMessage->load('sender'))))->toBe(PrivateChannel::class);
    expect(subscribes(new MessageEdited($dm)))->toBe(PrivateChannel::class);
    expect(subscribes(new MessageDeleted($dm)))->toBe(PrivateChannel::class);
    expect(subscribes(new MessageEdited($clanMessage)))->toBe(PrivateChannel::class);
    expect(subscribes(new FriendshipUpdated($recipient->id)))->toBe(PrivateChannel::class);
    expect(subscribes(new PresenceUpdated($recipient->id)))->toBe(PrivateChannel::class);
    expect(subscribes(new ClanUpdated($recipient->id)))->toBe(PrivateChannel::class);
    expect(subscribes(new RoomInvitationSent($recipient->id, 'ABC123', $sender->username)))
        ->toBe(PrivateChannel::class);
});

it('leaves the unguessable room channels public on purpose', function () {
    // Ini BUKAN kelalaian. Penonton dan deep-link undangan bergantung pada sifat publiknya, dan
    // kode 6 karakter acak memang memikul beban kerahasiaannya -- sekarang benar-benar begitu,
    // karena RoomInvitationSent tak lagi menyiarkannya ke channel yang bisa ditebak.
    expect(subscribes(new RoomUpdated('ABC123')))->toBe(Channel::class);
    expect(subscribes(new RoomMessageSent('ABC123', 1, 'ana', 'hai')))->toBe(Channel::class);
    expect(subscribes(new RaceProgressUpdated('ABC123', 1, ['progress_percent' => 50])))
        ->toBe(Channel::class);
});

/*
 * Aturannya diuji lewat App\Support\ChannelAccess, BUKAN lewat POST /broadcasting/auth.
 *
 * Suite berjalan dengan BROADCAST_CONNECTION=null, dan NullBroadcaster::auth() adalah no-op yang
 * mengembalikan 200 untuk siapa pun -- termasuk guest. Test yang menembak endpoint itu akan
 * tampak hijau tanpa membuktikan apa pun, yang justru lebih buruk daripada tak punya test.
 */

it('lets a user listen only on their own identity channel', function () {
    $me = User::factory()->create();
    $other = User::factory()->create();

    expect(ChannelAccess::ownsIdentityChannel($me, $me->id))->toBeTrue();

    // Inilah keseluruhan temuannya: satu baris di konsol dulu cukup untuk membaca DM orang lain.
    expect(ChannelAccess::ownsIdentityChannel($other, $me->id))->toBeFalse();

    // ID datang dari nama channel, jadi selalu berupa string -- perbandingannya harus tahan itu.
    expect(ChannelAccess::ownsIdentityChannel($me, (string) $me->id))->toBeTrue();

    expect(ChannelAccess::ownsIdentityChannel(null, $me->id))->toBeFalse();
});

it('lets only active clan members listen on clan chat', function () {
    [$leader, $clan] = userInClan('Private Fortress');
    $outsider = User::factory()->create();
    $applicant = User::factory()->create();

    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => $applicant->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Pending,
    ]);

    expect(ChannelAccess::mayReadClanChat($leader, $clan->id))->toBeTrue();
    expect(ChannelAccess::mayReadClanChat($outsider, $clan->id))->toBeFalse();

    // Permintaan bergabung yang belum disetujui bukan keanggotaan -- aturan yang sama persis
    // dengan yang menjaga jalur KIRIM di GuardsChatAccess.
    expect(ChannelAccess::mayReadClanChat($applicant, $clan->id))->toBeFalse();

    expect(ChannelAccess::mayReadClanChat(null, $clan->id))->toBeFalse();
});

it('registers an authorization callback for every private channel', function () {
    // Aturan yang benar tapi tak terpasang tak melindungi apa pun. routes/channels.php dulu hanya
    // memuat scaffolding 'App.Models.User.{id}' yang tak dipakai satu pun event.
    Broadcast::spy();

    require base_path('routes/channels.php');

    foreach (['chat.{userId}', 'clan-chat.{clanId}', 'friends.{userId}', 'clan.{userId}'] as $channel) {
        Broadcast::shouldHaveReceived('channel')->with($channel, Mockery::type('callable'))->once();
    }

    // Dan scaffolding matinya sudah tidak ada.
    Broadcast::shouldNotHaveReceived('channel', ['App.Models.User.{id}', Mockery::any()]);
});
