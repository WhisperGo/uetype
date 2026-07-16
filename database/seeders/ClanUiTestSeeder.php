<?php

namespace Database\Seeders;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeder khusus untuk MENCOBA UI clan (Kick, Leave, Join Requests, modal konfirmasi)
 * di environment lokal. Idempoten - aman dijalankan berulang.
 *
 * Membuat 2 user dummy (dummy@ / dummy2@uetype.test, cocok dengan /dev-login &
 * /dev-login2), menautkan mereka sebagai anggota clan milik LEADER pertama, plus satu
 * request Pending. Dengan ini:
 *  - Login sebagai leader  -> hover baris anggota -> tombol Kick -> modal.
 *  - Buka /dev-login2 (dummy2 = member biasa) -> /clans -> tombol Leave -> modal.
 *  - Leader juga melihat blok "Join Requests" (dummy pending) untuk Accept/Reject.
 */
class ClanUiTestSeeder extends Seeder
{
    public function run(): void
    {
        $clan = Clan::query()->orderBy('id')->first();

        if (! $clan) {
            $this->command->warn('Belum ada clan. Buat clan dulu lewat /clans, lalu jalankan seeder ini lagi.');

            return;
        }

        $dummy1 = User::firstOrCreate(
            ['email' => 'dummy@uetype.test'],
            ['username' => 'DummyTyper', 'google_id' => null, 'is_admin' => false],
        );

        $dummy2 = User::firstOrCreate(
            ['email' => 'dummy2@uetype.test'],
            ['username' => 'DummyTyper2', 'google_id' => null, 'is_admin' => false],
        );

        // dummy = anggota AKTIF (bisa di-kick oleh leader).
        ClanMember::updateOrCreate(
            ['clan_id' => $clan->id, 'user_id' => $dummy1->id],
            ['role' => ClanRole::Member, 'status' => ClanMemberStatus::Active],
        );

        // dummy2 = anggota AKTIF juga (login via /dev-login2 untuk mencoba Leave).
        ClanMember::updateOrCreate(
            ['clan_id' => $clan->id, 'user_id' => $dummy2->id],
            ['role' => ClanRole::Member, 'status' => ClanMemberStatus::Active],
        );

        $this->command->info("Clan '{$clan->name}' kini punya 2 anggota dummy (aktif).");
        $this->command->info('Leader: hover baris anggota -> Kick. Member: buka /dev-login2 lalu /clans -> Leave.');
    }
}
