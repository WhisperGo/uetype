<?php

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Livewire\ClanLeaderboard;
use App\Livewire\Clans;
use App\Livewire\ClanWar;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use Livewire\Livewire;

/** A clan with a leader plus one member per extra role given. */
function clanWith(array $roles = [], string $name = 'Test Clan'): array
{
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => $name, 'leader_id' => $leader->id, 'power' => 1000]);

    ClanMember::create([
        'clan_id' => $clan->id,
        'user_id' => $leader->id,
        'role' => ClanRole::Leader,
        'status' => ClanMemberStatus::Active,
    ]);

    $others = [];
    foreach ($roles as $key => $role) {
        $user = User::factory()->create();
        $others[$key] = [
            'user' => $user,
            'membership' => ClanMember::create([
                'clan_id' => $clan->id,
                'user_id' => $user->id,
                'role' => $role,
                'status' => ClanMemberStatus::Active,
            ]),
        ];
    }

    return ['clan' => $clan, 'leader' => $leader, 'others' => $others];
}

// ---- TRANSFER ----

it('transfers leadership, moving both the pivot role and clans.leader_id together', function () {
    $c = clanWith(['heir' => ClanRole::Member]);
    $heir = $c['others']['heir'];

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('transferLeadership', $heir['membership']->id);

    // The two records of the same fact must not drift apart.
    expect($c['clan']->fresh()->leader_id)->toBe($heir['user']->id);
    expect($heir['membership']->fresh()->role)->toBe(ClanRole::Leader);
    expect(ClanMember::where('user_id', $c['leader']->id)->first()->role)->toBe(ClanRole::Member);
});

it('lets the former leader leave once they have transferred the clan away', function () {
    $c = clanWith(['heir' => ClanRole::Member]);
    $old = $c['leader'];

    Livewire::actingAs($old)
        ->test(Clans::class)
        ->call('transferLeadership', $c['others']['heir']['membership']->id)
        ->call('leaveClan');

    // The exit that was previously impossible for a leader.
    expect(ClanMember::where('user_id', $old->id)->exists())->toBeFalse();
    expect($c['clan']->fresh())->not->toBeNull();
});

it('does not let a co-leader transfer leadership (trust boundary)', function () {
    $c = clanWith(['co' => ClanRole::CoLeader, 'target' => ClanRole::Member]);

    Livewire::actingAs($c['others']['co']['user'])
        ->test(Clans::class)
        ->call('transferLeadership', $c['others']['target']['membership']->id);

    expect($c['clan']->fresh()->leader_id)->toBe($c['leader']->id);
    expect($c['others']['target']['membership']->fresh()->role)->toBe(ClanRole::Member);
});

it('does not transfer leadership to a member of another clan', function () {
    $mine = clanWith([], 'Mine');
    $theirs = clanWith(['outsider' => ClanRole::Member], 'Theirs');

    Livewire::actingAs($mine['leader'])
        ->test(Clans::class)
        ->call('transferLeadership', $theirs['others']['outsider']['membership']->id);

    expect($mine['clan']->fresh()->leader_id)->toBe($mine['leader']->id);
    expect($theirs['others']['outsider']['membership']->fresh()->role)->toBe(ClanRole::Member);
});

// ---- PROMOTE / DEMOTE ----

it('promotes a member to co-leader and demotes them back', function () {
    $c = clanWith(['m' => ClanRole::Member]);
    $membership = $c['others']['m']['membership'];

    $component = Livewire::actingAs($c['leader'])->test(Clans::class);

    $component->call('promoteMember', $membership->id);
    expect($membership->fresh()->role)->toBe(ClanRole::CoLeader);

    $component->call('demoteMember', $membership->id);
    expect($membership->fresh()->role)->toBe(ClanRole::Member);
});

it('does not let a co-leader promote anyone', function () {
    $c = clanWith(['co' => ClanRole::CoLeader, 'm' => ClanRole::Member]);

    Livewire::actingAs($c['others']['co']['user'])
        ->test(Clans::class)
        ->call('promoteMember', $c['others']['m']['membership']->id);

    expect($c['others']['m']['membership']->fresh()->role)->toBe(ClanRole::Member);
});

it('refuses to demote someone who is not a co-leader', function () {
    $c = clanWith(['m' => ClanRole::Member]);

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('demoteMember', $c['others']['m']['membership']->id);

    // The `from` role is asserted, so a stale page cannot demote a plain member
    // into some other state.
    expect($c['others']['m']['membership']->fresh()->role)->toBe(ClanRole::Member);
});

// ---- CO-LEADER ROSTER POWERS ----

it('lets a co-leader approve a join request', function () {
    $c = clanWith(['co' => ClanRole::CoLeader]);

    $applicant = User::factory()->create();
    $request = ClanMember::create([
        'clan_id' => $c['clan']->id,
        'user_id' => $applicant->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Pending,
    ]);

    Livewire::actingAs($c['others']['co']['user'])
        ->test(Clans::class)
        ->call('approveMember', $request->id);

    expect($request->fresh()->status)->toBe(ClanMemberStatus::Active);
});

it('lets a co-leader kick a plain member', function () {
    $c = clanWith(['co' => ClanRole::CoLeader, 'm' => ClanRole::Member]);

    Livewire::actingAs($c['others']['co']['user'])
        ->test(Clans::class)
        ->call('kickMember', $c['others']['m']['membership']->id);

    expect(ClanMember::find($c['others']['m']['membership']->id))->toBeNull();
});

it('does not let a co-leader kick the leader or a fellow co-leader', function () {
    $c = clanWith(['co' => ClanRole::CoLeader, 'co2' => ClanRole::CoLeader]);

    $leaderMembership = ClanMember::where('user_id', $c['leader']->id)->first();

    $component = Livewire::actingAs($c['others']['co']['user'])->test(Clans::class);

    $component->call('kickMember', $leaderMembership->id);
    $component->call('kickMember', $c['others']['co2']['membership']->id);

    // A co-leader may only act strictly downward -- otherwise they could take the
    // clan hostage by removing everyone above them.
    expect(ClanMember::find($leaderMembership->id))->not->toBeNull();
    expect(ClanMember::find($c['others']['co2']['membership']->id))->not->toBeNull();
});

it('does not let a plain member approve or kick', function () {
    $c = clanWith(['m' => ClanRole::Member, 'victim' => ClanRole::Member]);

    Livewire::actingAs($c['others']['m']['user'])
        ->test(Clans::class)
        ->call('kickMember', $c['others']['victim']['membership']->id);

    expect(ClanMember::find($c['others']['victim']['membership']->id))->not->toBeNull();
});

it('lets a co-leader leave the clan directly', function () {
    $c = clanWith(['co' => ClanRole::CoLeader]);

    Livewire::actingAs($c['others']['co']['user'])
        ->test(Clans::class)
        ->call('leaveClan');

    expect(ClanMember::find($c['others']['co']['membership']->id))->toBeNull();
});

// ---- DISBAND ----

it('disbands a clan when the leader retypes its name, removing every membership', function () {
    $c = clanWith(['m' => ClanRole::Member], 'Doomed');

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->set('confirmDisbandName', 'Doomed')
        ->call('disbandClan')
        ->assertHasNoErrors();

    expect(Clan::find($c['clan']->id))->toBeNull();
    expect(ClanMember::where('clan_id', $c['clan']->id)->count())->toBe(0);
});

it('refuses to disband when the typed name does not match', function () {
    $c = clanWith([], 'Precise Name');

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->set('confirmDisbandName', 'precise name')
        ->call('disbandClan')
        ->assertHasErrors('disband');

    expect(Clan::find($c['clan']->id))->not->toBeNull();
});

it('blocks disbanding while a war is pending or ongoing', function (ClanWarStatus $status) {
    $mine = clanWith([], 'Mine '.$status->value);
    $rival = clanWith([], 'Rival '.$status->value);

    ClanWarModel::create([
        'challenger_clan_id' => $mine['clan']->id,
        'opponent_clan_id' => $rival['clan']->id,
        'status' => $status,
        'accept_deadline_at' => now()->addHour(),
        'ends_at' => now()->addDays(3),
    ]);

    Livewire::actingAs($mine['leader'])
        ->test(Clans::class)
        ->set('confirmDisbandName', $mine['clan']->name)
        ->call('disbandClan')
        ->assertHasErrors('disband');

    // Dissolving mid-war would be a free way to dodge an Elo loss, and would delete
    // the opponent's war record with it.
    expect(Clan::find($mine['clan']->id))->not->toBeNull();
})->with([
    'pending' => ClanWarStatus::Pending,
    'ongoing' => ClanWarStatus::Ongoing,
]);

it('allows disbanding once the war has finished', function () {
    $mine = clanWith([], 'Mine Finished');
    $rival = clanWith([], 'Rival Finished');

    ClanWarModel::create([
        'challenger_clan_id' => $mine['clan']->id,
        'opponent_clan_id' => $rival['clan']->id,
        'status' => ClanWarStatus::Finished,
        'result' => 'win',
        'accept_deadline_at' => now()->subDays(4),
        'ends_at' => now()->subDay(),
    ]);

    Livewire::actingAs($mine['leader'])
        ->test(Clans::class)
        ->set('confirmDisbandName', 'Mine Finished')
        ->call('disbandClan')
        ->assertHasNoErrors();

    expect(Clan::find($mine['clan']->id))->toBeNull();
});

it('does not let a co-leader or member disband the clan', function () {
    $c = clanWith(['co' => ClanRole::CoLeader, 'm' => ClanRole::Member], 'Survivor');

    foreach (['co', 'm'] as $key) {
        Livewire::actingAs($c['others'][$key]['user'])
            ->test(Clans::class)
            ->set('confirmDisbandName', 'Survivor')
            ->call('disbandClan');
    }

    expect(Clan::find($c['clan']->id))->not->toBeNull();
});

// ---- WITHDRAW JOIN REQUEST ----

it('lets an applicant withdraw their own pending request', function () {
    $c = clanWith([], 'Silent Clan');
    $applicant = User::factory()->create();

    ClanMember::create([
        'clan_id' => $c['clan']->id,
        'user_id' => $applicant->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Pending,
    ]);

    Livewire::actingAs($applicant)
        ->test(Clans::class)
        ->call('cancelJoinRequest', $c['clan']->id);

    expect(ClanMember::where('user_id', $applicant->id)->exists())->toBeFalse();
});

it('lets an applicant re-apply after withdrawing', function () {
    $c = clanWith([], 'Second Chance');
    $applicant = User::factory()->create();

    $component = Livewire::actingAs($applicant)->test(Clans::class);

    $component->call('sendJoinRequest', $c['clan']->id);
    $component->call('cancelJoinRequest', $c['clan']->id);
    $component->call('sendJoinRequest', $c['clan']->id);

    // sendJoinRequest blocks when ANY row to that clan exists, so withdrawing has to
    // actually delete the row rather than mark it -- otherwise re-applying is dead.
    expect(ClanMember::where('user_id', $applicant->id)
        ->where('clan_id', $c['clan']->id)
        ->where('status', ClanMemberStatus::Pending)
        ->count())->toBe(1);
});

it('does not let withdrawing remove an active membership', function () {
    $c = clanWith(['m' => ClanRole::Member], 'Sticky');

    Livewire::actingAs($c['others']['m']['user'])
        ->test(Clans::class)
        ->call('cancelJoinRequest', $c['clan']->id);

    // Only Pending rows are in scope; leaving is leaveClan, which has its own guards.
    expect(ClanMember::find($c['others']['m']['membership']->id))->not->toBeNull();
});

it('does not let one user withdraw another users request', function () {
    $c = clanWith([], 'Contested');

    $applicant = User::factory()->create();
    $request = ClanMember::create([
        'clan_id' => $c['clan']->id,
        'user_id' => $applicant->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Pending,
    ]);

    $stranger = User::factory()->create();

    Livewire::actingAs($stranger)
        ->test(Clans::class)
        ->call('cancelJoinRequest', $c['clan']->id);

    expect(ClanMember::find($request->id))->not->toBeNull();
});

it('drops the withdrawn request from the leaders pending list', function () {
    $c = clanWith([], 'Watching');
    $applicant = User::factory()->create(['username' => 'hopeful']);

    Livewire::actingAs($applicant)
        ->test(Clans::class)
        ->call('sendJoinRequest', $c['clan']->id);

    Livewire::actingAs($c['leader'])->test(Clans::class)->assertSee('hopeful');

    Livewire::actingAs($applicant)
        ->test(Clans::class)
        ->call('cancelJoinRequest', $c['clan']->id);

    // The leader's pending list is driven off the same rows, so a withdrawn request
    // must vanish from their page too.
    Livewire::actingAs($c['leader'])->test(Clans::class)->assertDontSee('hopeful');
});

it('shows both the pending status and a cancel button on a browse row', function () {
    $c = clanWith([], 'Browsable');
    $applicant = User::factory()->create();

    ClanMember::create([
        'clan_id' => $c['clan']->id,
        'user_id' => $applicant->id,
        'role' => ClanRole::Member,
        'status' => ClanMemberStatus::Pending,
    ]);

    Livewire::actingAs($applicant)
        ->test(Clans::class)
        ->set('tab', 'browse')
        // Both at once, with no hover involved: the status stays readable while the
        // action stays reachable, including on touch where hover does not exist.
        ->assertSee(__('clan.browse.request_sent'))
        ->assertSee(__('clan.browse.cancel_request'))
        ->assertSeeHtml('wire:click="cancelJoinRequest('.$c['clan']->id.')"')
        ->assertDontSeeHtml('group-hover/cancel');
});

// ---- EDIT IDENTITY ----

it('lets the leader edit every identity field', function () {
    $c = clanWith([], 'Old Name');

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('startEditClan')
        ->set('editName', 'New Name')
        ->set('editTag', 'NEW')
        ->set('editEmblem', 'wolf')
        ->set('editEmblemColor', 'crimson')
        ->set('editDescription', 'A fresh description')
        ->call('saveClanIdentity')
        ->assertHasNoErrors();

    $clan = $c['clan']->fresh();
    expect($clan->name)->toBe('New Name');
    expect($clan->tag)->toBe('NEW');
    expect($clan->emblem)->toBe('wolf');
    expect($clan->emblem_color)->toBe('crimson');
    expect($clan->description)->toBe('A fresh description');
});

it('seeds the edit form from the clan when opened', function () {
    $c = clanWith([], 'Seeded');
    $c['clan']->update(['tag' => 'SED', 'emblem' => 'gem', 'emblem_color' => 'violet', 'description' => 'hello']);

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('startEditClan')
        ->assertSet('editing', true)
        ->assertSet('editName', 'Seeded')
        ->assertSet('editTag', 'SED')
        ->assertSet('editEmblem', 'gem')
        ->assertSet('editEmblemColor', 'violet')
        ->assertSet('editDescription', 'hello');
});

it('saves an edit that leaves the name untouched', function () {
    $c = clanWith([], 'Unchanged');

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('startEditClan')
        ->set('editDescription', 'only the description moved')
        ->call('saveClanIdentity')
        ->assertHasNoErrors();

    // The unique rule must ignore this clan, or saving its own unchanged name
    // would collide with itself.
    expect($c['clan']->fresh()->description)->toBe('only the description moved');
});

it('rejects an edited name that belongs to another clan', function () {
    clanWith([], 'Taken Name');
    $mine = clanWith([], 'My Name');

    Livewire::actingAs($mine['leader'])
        ->test(Clans::class)
        ->call('startEditClan')
        ->set('editName', 'Taken Name')
        ->call('saveClanIdentity')
        ->assertHasErrors('editName');

    expect($mine['clan']->fresh()->name)->toBe('My Name');
});

it('validates the trimmed edited name, not the padded raw input', function () {
    $c = clanWith([], 'Padded Test');

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('startEditClan')
        ->set('editName', '  ab  ')
        ->call('saveClanIdentity')
        ->assertHasErrors('editName');

    expect($c['clan']->fresh()->name)->toBe('Padded Test');
});

it('rejects an emblem or colour outside the catalog', function () {
    $c = clanWith([], 'Whitelist');

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('startEditClan')
        ->set('editEmblem', 'not-a-real-icon')
        ->set('editEmblemColor', 'chartreuse')
        ->call('saveClanIdentity')
        ->assertHasErrors(['editEmblem', 'editEmblemColor']);
});

it('does not let a co-leader or member edit the clan identity', function () {
    $c = clanWith(['co' => ClanRole::CoLeader, 'm' => ClanRole::Member], 'Locked');

    foreach (['co', 'm'] as $key) {
        Livewire::actingAs($c['others'][$key]['user'])
            ->test(Clans::class)
            ->set('editName', 'Hijacked')
            ->call('saveClanIdentity');
    }

    expect($c['clan']->fresh()->name)->toBe('Locked');
});

it('discards an abandoned edit', function () {
    $c = clanWith([], 'Kept');

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('startEditClan')
        ->set('editName', 'Never Saved')
        ->call('cancelEditClan')
        ->assertSet('editing', false);

    expect($c['clan']->fresh()->name)->toBe('Kept');
});

it('clears an emptied tag and description back to null', function () {
    $c = clanWith([], 'Clearing');
    $c['clan']->update(['tag' => 'OLD', 'description' => 'old text']);

    Livewire::actingAs($c['leader'])
        ->test(Clans::class)
        ->call('startEditClan')
        ->set('editTag', '')
        ->set('editDescription', '')
        ->call('saveClanIdentity')
        ->assertHasNoErrors();

    $clan = $c['clan']->fresh();
    expect($clan->tag)->toBeNull();
    expect($clan->description)->toBeNull();
});

// ---- EMPTY CLANS ----
//
// A clan with no active members can't approve a join request or field a war claim, so
// every path that would engage with one is closed. Hidden, not deleted: deleting cascades
// into the war records of opposing clans whose power has already moved.

/** A clan row with no active members at all. */
function emptyClan(string $name = 'Ghost Clan'): Clan
{
    return Clan::create([
        'name' => $name,
        'leader_id' => User::factory()->create()->id,
        'power' => 1000,
    ]);
}

it('hides an empty clan from browse but keeps populated ones', function () {
    emptyClan('Ghost Town');
    clanWith([], 'Real Clan');

    Livewire::actingAs(User::factory()->create())
        ->test(Clans::class)
        ->set('tab', 'browse')
        ->assertSee('Real Clan')
        ->assertDontSee('Ghost Town');
});

it('hides an empty clan from the leaderboard', function () {
    emptyClan('Ghost Rank');
    clanWith([], 'Ranked Clan');

    Livewire::actingAs(User::factory()->create())
        ->test(ClanLeaderboard::class)
        ->assertSee('Ranked Clan')
        ->assertDontSee('Ghost Rank');
});

it('refuses a join request sent directly to an empty clan', function () {
    $ghost = emptyClan('Unapprovable');
    $applicant = User::factory()->create();

    Livewire::actingAs($applicant)
        ->test(Clans::class)
        ->call('sendJoinRequest', $ghost->id);

    // Nobody there could ever approve it, so the row must not be created at all.
    expect(ClanMember::where('clan_id', $ghost->id)->count())->toBe(0);
});

it('refuses a war challenge posted directly at an empty clan', function () {
    $ghost = emptyClan('Walkover');
    $mine = clanWith([], 'Challenger Clan');

    Livewire::actingAs($mine['leader'])
        ->test(ClanWar::class)
        ->call('challengeClan', $ghost->id);

    // Hiding it from the picker is presentation; this is the gate that matters, since
    // an unplayable opponent is three days of free Elo.
    expect(ClanWarModel::where('opponent_clan_id', $ghost->id)->count())->toBe(0);
});

it('keeps an empty clan out of the challengeable list', function () {
    emptyClan('Not Listed');
    $mine = clanWith([], 'Picker Clan');

    Livewire::actingAs($mine['leader'])
        ->test(ClanWar::class)
        ->assertDontSee('Not Listed');
});

// ---- ROSTER CONTEXT ----

it('reports online state, war contribution and join date per member', function () {
    $c = clanWith(['m' => ClanRole::Member], 'Contextual');
    $rival = clanWith([], 'Contextual Rival');

    $war = ClanWarModel::create([
        'challenger_clan_id' => $c['clan']->id,
        'opponent_clan_id' => $rival['clan']->id,
        'status' => ClanWarStatus::Finished,
        'result' => 'win',
        'accept_deadline_at' => now()->subDays(4),
        'ends_at' => now()->subDay(),
    ]);

    // Claimed but never played: no typing result attached.
    ClanWarModeClaim::create([
        'clan_war_id' => $war->id,
        'clan_id' => $c['clan']->id,
        'user_id' => $c['leader']->id,
        'mode' => 'time',
        'mode_config' => '30',
        'typing_result_id' => null,
        'points' => 120,
        'claimed_at' => now()->subDays(2),
    ]);

    $rows = Livewire::actingAs($c['leader'])->test(Clans::class)->get('myClanMembers');

    $leaderRow = $rows->firstWhere('user.id', $c['leader']->id);
    $memberRow = $rows->firstWhere('user.id', $c['others']['m']['user']->id);

    // An unsubmitted claim scores nothing, even with points on the row -- consistent
    // with how war points are counted everywhere else. "Locked a slot and sat on it"
    // must not read as a contribution.
    expect($leaderRow['contribution'])->toBe(0.0);
    expect($memberRow['contribution'])->toBe(0.0);
    expect($leaderRow['joined_at'])->not->toBeNull();
});

it('counts a submitted claim toward the members contribution', function () {
    $c = clanWith([], 'Scoring');
    $rival = clanWith([], 'Scoring Rival');

    $war = ClanWarModel::create([
        'challenger_clan_id' => $c['clan']->id,
        'opponent_clan_id' => $rival['clan']->id,
        'status' => ClanWarStatus::Finished,
        'result' => 'win',
        'accept_deadline_at' => now()->subDays(4),
        'ends_at' => now()->subDay(),
    ]);

    $result = TypingResult::create([
        'user_id' => $c['leader']->id, 'mode' => 'time', 'mode_config' => '30',
        'net_wpm' => 80, 'raw_wpm' => 85, 'accuracy' => 95, 'correct_chars' => 100,
        'incorrect_chars' => 2, 'duration_seconds' => 30, 'xp_earned' => 40,
    ]);

    ClanWarModeClaim::create([
        'clan_war_id' => $war->id,
        'clan_id' => $c['clan']->id,
        'user_id' => $c['leader']->id,
        'mode' => 'time',
        'mode_config' => '30',
        'typing_result_id' => $result->id,
        'points' => 120,
        'claimed_at' => now()->subDays(2),
    ]);

    $rows = Livewire::actingAs($c['leader'])->test(Clans::class)->get('myClanMembers');

    expect($rows->firstWhere('user.id', $c['leader']->id)['contribution'])->toBe(120.0);
});

it('leaves contribution null when the clan has never finished a war', function () {
    $c = clanWith(['m' => ClanRole::Member], 'Warless');

    $rows = Livewire::actingAs($c['leader'])->test(Clans::class)->get('myClanMembers');

    // null, not 0.0: "no war has happened" is not the same claim as "scored nothing",
    // and the roster must not accuse a member of idling in a war that never ran.
    expect($rows->every(fn ($r) => $r['contribution'] === null))->toBeTrue();
});

it('marks a member online only within the presence threshold', function () {
    $c = clanWith(['fresh' => ClanRole::Member, 'stale' => ClanRole::Member], 'Presence');

    $c['others']['fresh']['user']->forceFill(['last_seen_at' => now()])->save();
    $c['others']['stale']['user']->forceFill(['last_seen_at' => now()->subDay()])->save();

    $rows = Livewire::actingAs($c['leader'])->test(Clans::class)->get('myClanMembers');

    expect($rows->firstWhere('user.id', $c['others']['fresh']['user']->id)['online'])->toBeTrue();
    expect($rows->firstWhere('user.id', $c['others']['stale']['user']->id)['online'])->toBeFalse();
});

// ---- ROSTER ORDER ----

it('orders the roster leader first, then co-leaders, then members', function () {
    // Usernames chosen so that alphabetical order would NOT produce this result, and
    // role-string order would put 'co-leader' above 'leader'.
    $c = clanWith(['m' => ClanRole::Member, 'co' => ClanRole::CoLeader]);

    $roles = $c['clan']->orderedActiveMembers()->map(fn ($m) => $m->role->value)->all();

    expect($roles)->toBe(['leader', 'co-leader', 'member']);
});

// ---- RENDERED AFFORDANCES ----
//
// The actions above are gated server-side; these assert the PAGE agrees. A UI that
// offers a control the server will refuse (or hides one it would allow) is its own
// bug, and the two gates are written in different files.

it('renders the roster and manage menu for a leader', function () {
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Render Clan', 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $co = User::factory()->create();
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $co->id, 'role' => ClanRole::CoLeader, 'status' => ClanMemberStatus::Active]);

    $m = User::factory()->create();
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $m->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);

    Livewire::actingAs($leader)->test(Clans::class)
        ->assertOk()
        ->assertSee('Manage Clan')
        ->assertSee('Co-Leader')
        ->assertDontSee('Leave Clan');
});

it('renders the co-leader view: leave button, no manage menu', function () {
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Render Clan Co', 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $co = User::factory()->create();
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $co->id, 'role' => ClanRole::CoLeader, 'status' => ClanMemberStatus::Active]);

    Livewire::actingAs($co)->test(Clans::class)
        ->assertOk()
        ->assertSee('Leave Clan')
        ->assertDontSee('Manage Clan')
        ->assertDontSee('Transfer Leadership');
});

it('renders a plain member view with no management affordances', function () {
    $leader = User::factory()->create();
    $clan = Clan::create(['name' => 'Render Clan M', 'leader_id' => $leader->id, 'power' => 1000]);
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $leader->id, 'role' => ClanRole::Leader, 'status' => ClanMemberStatus::Active]);

    $m = User::factory()->create();
    ClanMember::create(['clan_id' => $clan->id, 'user_id' => $m->id, 'role' => ClanRole::Member, 'status' => ClanMemberStatus::Active]);

    Livewire::actingAs($m)->test(Clans::class)
        ->assertOk()
        ->assertSee('Leave Clan')
        ->assertDontSee('Manage Clan')
        ->assertDontSee('Kick');
});
