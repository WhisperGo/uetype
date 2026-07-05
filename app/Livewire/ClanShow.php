<?php

namespace App\Livewire;

use App\Enums\ClanRole;
use App\Models\Clan;
use Livewire\Component;

class ClanShow extends Component
{
    public Clan $clan;

    public function mount(Clan $clan): void
    {
        $this->clan = $clan;
    }

    public function getMembersProperty()
    {
        return $this->clan->activeMembers()->with('user')->orderBy('role')->get();
    }

    /**
     * Riwayat war selesai clan ini, sudah diringkas per sudut pandang clan
     * (hasil, lawan, delta power) lewat helper di model.
     */
    public function getHistoryProperty()
    {
        return $this->clan->finishedWars()
            ->map(fn ($war) => array_merge(
                ['war' => $war],
                $this->clan->warSummary($war)
            ));
    }

    public function getIsLeaderRole(): string
    {
        return ClanRole::Leader->value;
    }

    public function render()
    {
        return view('livewire.clan-show')->layout('layouts.app');
    }
}
