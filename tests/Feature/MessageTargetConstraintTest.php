<?php

use App\Models\Clan;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * `messages` memakai satu tabel untuk dua jenis chat, dibedakan kolom nullable:
 * DM mengisi recipient_id, clan chat mengisi clan_id. Tepat satu harus terisi.
 *
 * Invarian itu ditegakkan DI LEVEL DB (CHECK constraint), bukan sekadar disiplin
 * aplikasi. Alasannya: `messages` ditulis dari beberapa jalur -- Chat, ChatOverlay,
 * ChatController, dan seeder -- sehingga satu pemanggil yang lupa mengisi target
 * sudah cukup membuat baris tak bermakna yang tak akan pernah tampil di UI mana pun.
 *
 * Test ini menguji penjagaan itu lewat query builder mentah, jadi ia tetap jujur
 * meski validasi aplikasi berubah.
 */
function makeTargetClan(): Clan
{
    return Clan::create([
        'name' => 'Clan '.Str::random(6),
        'leader_id' => User::factory()->create()->id,
    ]);
}

function insertRawMessage(?int $recipientId, ?int $clanId): void
{
    DB::table('messages')->insert([
        'sender_id' => User::factory()->create()->id,
        'recipient_id' => $recipientId,
        'clan_id' => $clanId,
        'body' => 'halo',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('rejects a message that targets both a user and a clan', function () {
    $recipient = User::factory()->create();
    $clan = makeTargetClan();

    // Query builder mentah, bukan model: yang diuji penjagaan DB, bukan validasi
    // aplikasi. Kalau lapisan DB-nya bolong, ini yang menangkapnya.
    insertRawMessage($recipient->id, $clan->id);
})->throws(QueryException::class);

it('rejects a message that targets neither a user nor a clan', function () {
    insertRawMessage(null, null);
})->throws(QueryException::class);

it('accepts a direct message and a clan message', function () {
    $recipient = User::factory()->create();
    $clan = makeTargetClan();

    insertRawMessage($recipient->id, null);
    insertRawMessage(null, $clan->id);

    expect(DB::table('messages')->count())->toBe(2);
});

it('rejects an update that breaks the invariant on an existing row', function () {
    $recipient = User::factory()->create();
    $clan = makeTargetClan();

    insertRawMessage($recipient->id, null);

    // Baris yang sah saat disisipkan diubah jadi melanggar. Penjagaan yang hanya
    // berlaku saat INSERT akan meloloskan ini, dan tanpa test tersendiri celah
    // itu tak akan kelihatan.
    DB::table('messages')->update(['clan_id' => $clan->id]);
})->throws(QueryException::class);

/**
 * `message_clears` memakai bentuk target yang sama (other_user_id XOR clan_id),
 * dan penjagaannya dipasang di migrasi yang sama. Kalau salah satu invarian
 * hilang tanpa yang lain, di sinilah ketahuannya.
 */
it('rejects a clear marker that targets both a user and a clan', function () {
    DB::table('message_clears')->insert([
        'user_id' => User::factory()->create()->id,
        'other_user_id' => User::factory()->create()->id,
        'clan_id' => makeTargetClan()->id,
        'cleared_before' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('rejects a clear marker that targets neither', function () {
    DB::table('message_clears')->insert([
        'user_id' => User::factory()->create()->id,
        'other_user_id' => null,
        'clan_id' => null,
        'cleared_before' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);
