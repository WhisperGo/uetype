<?php

namespace Database\Seeders;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\User;
use App\Support\ClanEmblem;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class DummyClanSeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create('id_ID');

        $blueprints = [
            ['name' => 'Velocity', 'tag' => 'VLC', 'power' => 1420],
            ['name' => 'Keystroke Kings', 'tag' => 'KSK', 'power' => 1310],
            ['name' => 'Rapid Fingers', 'tag' => 'RPD', 'power' => 1240],
            ['name' => 'Night Owls', 'tag' => 'OWL', 'power' => 1150],
            ['name' => 'Type Titans', 'tag' => 'TTN', 'power' => 1080],
            ['name' => 'Fresh Recruits', 'tag' => 'NEW', 'power' => 980],
            ['name' => 'Slow & Steady', 'tag' => 'SNS', 'power' => 920],
        ];

        $icons = ClanEmblem::iconKeys();
        $colors = ClanEmblem::colorKeys();

        // Dummy users not yet in any clan, used as leaders/members. Idempotent: a user who
        // already has a clan_member row is skipped.
        $takenIds = ClanMember::pluck('user_id')->all();

        $available = User::whereNotIn('id', $takenIds)->get()->shuffle();

        if ($available->isEmpty()) {
            $available = User::all()->shuffle();
        }

        $cursor = 0;

        foreach ($blueprints as $i => $bp) {
            if ($cursor >= $available->count()) {
                break;
            }

            $leader = $available[$cursor];
            $cursor++;

            $clan = Clan::firstOrCreate(
                ['name' => $bp['name']],
                [
                    'tag' => $bp['tag'],
                    'emblem' => $icons[$i % count($icons)],
                    'emblem_color' => $colors[$i % count($colors)],
                    'description' => $faker->sentence(6),
                    'leader_id' => $leader->id,
                    'power' => $bp['power'],
                ],
            );

            ClanMember::firstOrCreate(
                ['clan_id' => $clan->id, 'user_id' => $leader->id],
                ['role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active],
            );

            $memberCount = rand(2, 6);
            for ($m = 0; $m < $memberCount && $cursor < $available->count(); $m++) {
                $member = $available[$cursor];
                $cursor++;

                ClanMember::firstOrCreate(
                    ['clan_id' => $clan->id, 'user_id' => $member->id],
                    ['role' => ClanRole::Member, 'status' => ClanMemberStatus::Active],
                );
            }
        }

        $this->command->info('Sukses menggenerasikan clan contoh beserta membernya!');
    }
}
