<?php

use App\Livewire\Clans;
use App\Models\Clan;
use App\Models\User;
use App\Support\ClanEmblem;
use Livewire\Livewire;

it('derives clan level monotonically from power with base power at level 1', function () {
    expect(Clan::levelFromPower(Clan::BASE_POWER))->toBe(1)
        ->and(Clan::levelFromPower(Clan::BASE_POWER - 500))->toBe(1)
        ->and(Clan::levelFromPower(Clan::BASE_POWER + Clan::POWER_PER_LEVEL))->toBe(2)
        ->and(Clan::levelFromPower(Clan::BASE_POWER + Clan::POWER_PER_LEVEL * 4))->toBe(5);

    $prev = 0;
    foreach ([900, 1000, 1100, 1300, 1500, 2000] as $power) {
        $level = Clan::levelFromPower($power);
        expect($level)->toBeGreaterThanOrEqual($prev);
        $prev = $level;
    }
});

it('exposes level data derived from power', function () {
    $clan = new Clan(['power' => Clan::BASE_POWER + 50]);
    $data = $clan->levelData();

    expect($data['level'])->toBe(1)
        ->and($data['progress'])->toBe(50)
        ->and($data['needed'])->toBe(Clan::POWER_PER_LEVEL);
});

it('persists a chosen emblem, color and description when creating a clan', function () {
    $me = User::factory()->create();

    Livewire::actingAs($me)
        ->test(Clans::class)
        ->set('newName', 'Velocity Squad')
        ->set('newEmblem', 'bolt')
        ->set('newEmblemColor', 'sky')
        ->set('newDescription', 'We type fast.')
        ->call('createClan');

    $this->assertDatabaseHas('clans', [
        'name' => 'Velocity Squad',
        'emblem' => 'bolt',
        'emblem_color' => 'sky',
        'description' => 'We type fast.',
    ]);
});

it('rejects an emblem or color that is not in the preset whitelist', function () {
    $me = User::factory()->create();

    Livewire::actingAs($me)
        ->test(Clans::class)
        ->set('newName', 'Bad Emblem Clan')
        ->set('newEmblem', 'definitely-not-real')
        ->set('newEmblemColor', 'gold')
        ->call('createClan')
        ->assertHasErrors('newEmblem');

    expect(Clan::where('name', 'Bad Emblem Clan')->exists())->toBeFalse();

    Livewire::actingAs($me)
        ->test(Clans::class)
        ->set('newName', 'Bad Color Clan')
        ->set('newEmblem', 'shield')
        ->set('newEmblemColor', '#ffffff')
        ->call('createClan')
        ->assertHasErrors('newEmblemColor');

    expect(Clan::where('name', 'Bad Color Clan')->exists())->toBeFalse();
});

it('validates every preset key so the picker cannot desync from the model', function () {
    foreach (ClanEmblem::iconKeys() as $icon) {
        expect(ClanEmblem::isValidIcon($icon))->toBeTrue();
    }
    foreach (ClanEmblem::colorKeys() as $color) {
        expect(ClanEmblem::isValidColor($color))->toBeTrue();
    }

    expect(ClanEmblem::isValidIcon('nope'))->toBeFalse()
        ->and(ClanEmblem::isValidColor('nope'))->toBeFalse();
});
