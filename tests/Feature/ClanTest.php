<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Livewire\Clans;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\User;
use Livewire\Livewire;

it('creates a clan and makes the creator an active leader', function () {
    $me = User::factory()->create();

    Livewire::actingAs($me)
        ->test(Clans::class)
        ->set('newName', 'Speed Demons')
        ->set('newTag', 'SPD')
        ->call('createClan');

    $this->assertDatabaseHas('clans', ['name' => 'Speed Demons', 'tag' => 'SPD', 'leader_id' => $me->id]);
    $this->assertDatabaseHas('clan_members', [
        'user_id' => $me->id,
        'role' => ClanRole::Leader->value,
        'status' => ClanMemberStatus::Active->value,
    ]);
});

it('sends a join request and lets the leader approve it', function () {
    $leader = User::factory()->create();
    $applicant = User::factory()->create();

    $clan = Clan::create(['name' => 'Night Owls', 'leader_id' => $leader->id]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    Livewire::actingAs($applicant)
        ->test(Clans::class)
        ->call('sendJoinRequest', $clan->id);

    $this->assertDatabaseHas('clan_members', [
        'clan_id' => $clan->id,
        'user_id' => $applicant->id,
        'status' => ClanMemberStatus::Pending->value,
    ]);

    $pending = ClanMember::where('clan_id', $clan->id)->where('user_id', $applicant->id)->first();

    Livewire::actingAs($leader)
        ->test(Clans::class)
        ->call('approveMember', $pending->id);

    $this->assertDatabaseHas('clan_members', [
        'id' => $pending->id,
        'status' => ClanMemberStatus::Active->value,
    ]);
});

it('does not let a non-leader approve a join request for someone elses clan (trust boundary)', function () {
    $leader = User::factory()->create();
    $applicant = User::factory()->create();
    $stranger = User::factory()->create();

    $clan = Clan::create(['name' => 'Night Owls', 'leader_id' => $leader->id]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);
    $pending = ClanMember::create(['clan_id' => $clan->id, 'user_id' => $applicant->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Pending]);

    Livewire::actingAs($stranger)
        ->test(Clans::class)
        ->call('approveMember', $pending->id);

    $this->assertDatabaseHas('clan_members', [
        'id' => $pending->id,
        'status' => ClanMemberStatus::Pending->value,
    ]);
});

it('blocks a user from joining a second clan while already a member', function () {
    $leaderA = User::factory()->create();
    $leaderB = User::factory()->create();
    $member = User::factory()->create();

    $clanA = Clan::create(['name' => 'Clan A', 'leader_id' => $leaderA->id]);
    ClanMember::create(['clan_id' => $clanA->id, 'user_id' => $leaderA->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);
    ClanMember::create(['clan_id' => $clanA->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);

    $clanB = Clan::create(['name' => 'Clan B', 'leader_id' => $leaderB->id]);
    ClanMember::create(['clan_id' => $clanB->id, 'user_id' => $leaderB->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    Livewire::actingAs($member)
        ->test(Clans::class)
        ->call('sendJoinRequest', $clanB->id);

    $this->assertDatabaseMissing('clan_members', ['clan_id' => $clanB->id, 'user_id' => $member->id]);
});

it('rejects approving a member once the clan reaches the 20-member cap', function () {
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Full House', 'leader_id' => $leader->id]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    // Isi sampai 19 member aktif lagi (total 20 dengan leader).
    for ($i = 0; $i < 19; $i++) {
        $u = User::factory()->create();
        ClanMember::create(['clan_id' => $clan->id, 'user_id' => $u->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);
    }

    $applicant = User::factory()->create();
    $pending = ClanMember::create(['clan_id' => $clan->id, 'user_id' => $applicant->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Pending]);

    expect($clan->activeMembers()->count())->toBe(20);

    Livewire::actingAs($leader)
        ->test(Clans::class)
        ->call('approveMember', $pending->id);

    $this->assertDatabaseHas('clan_members', [
        'id' => $pending->id,
        'status' => ClanMemberStatus::Pending->value,
    ]);
});

it('lets a member leave but blocks the leader from leaving directly', function () {
    $leader = User::factory()->create();
    $member = User::factory()->create();

    $clan = Clan::create(['name' => 'Departure Test', 'leader_id' => $leader->id]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $member->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);

    Livewire::actingAs($member)->test(Clans::class)->call('leaveClan');
    $this->assertDatabaseMissing('clan_members', ['clan_id' => $clan->id, 'user_id' => $member->id]);

    Livewire::actingAs($leader)->test(Clans::class)->call('leaveClan');
    $this->assertDatabaseHas('clan_members', ['clan_id' => $clan->id, 'user_id' => $leader->id, 'status' => ClanMemberStatus::Active->value]);
});

it('requires authentication to view the clans page', function () {
    $this->get(route('clans.index'))->assertRedirect(route('login'));
});
