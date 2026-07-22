<?php

namespace Database\Seeders;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * A dedicated seeder for TRYING OUT the clan UI (Kick, Leave, Join Requests, confirmation
 * modal) in the local environment. Idempotent - safe to run repeatedly.
 *
 * Creates 2 dummy users (dummy@ / dummy2@uetype.test, matching /dev-login & /dev-login2),
 * links them as members of the first LEADER's clan, plus one Pending request. With this:
 *  - Log in as the leader   -> hover a member row -> Kick button -> modal.
 *  - Open /dev-login2 (dummy2 = a regular member) -> /clans -> Leave button -> modal.
 *  - The leader also sees the "Join Requests" block (a pending dummy) for Accept/Reject.
 */
class ClanUiTestSeeder extends Seeder
{
    public function run(): void
    {
        $clan = Clan::query()->orderBy('id')->first();

        if (! $clan) {
            $this->command->warn('No clan yet. Create one via /clans first, then run this seeder again.');

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

        // dummy = an ACTIVE member (can be kicked by the leader).
        ClanMember::updateOrCreate(
            ['clan_id' => $clan->id, 'user_id' => $dummy1->id],
            ['role' => ClanRole::Member, 'status' => ClanMemberStatus::Active],
        );

        // dummy2 = also an ACTIVE member (log in via /dev-login2 to try Leave).
        ClanMember::updateOrCreate(
            ['clan_id' => $clan->id, 'user_id' => $dummy2->id],
            ['role' => ClanRole::Member, 'status' => ClanMemberStatus::Active],
        );

        $this->command->info("Clan '{$clan->name}' kini punya 2 anggota dummy (aktif).");
        $this->command->info('Leader: hover a member row -> Kick. Member: open /dev-login2 then /clans -> Leave.');
    }
}
