<?php

namespace App\Livewire;

use App\Enums\ClanRole;
use App\Models\Clan;
use Livewire\Component;

/** A single clan's detail page: profile, active members, and stats (read-only). */
class ClanShow extends Component
{
    public Clan $clan;

    public function mount(Clan $clan): void
    {
        $this->clan = $clan;
    }

    /** Active members of this clan, ordered by role. */
    public function getMembersProperty()
    {
        return $this->clan->activeMembers()->with('user')->orderBy('role')->get();
    }

    /**
     * This clan's finished-war history, summarized from the clan's viewpoint
     * (result, opponent, power delta) via a model helper.
     */
    public function getHistoryProperty()
    {
        return $this->clan->finishedWars()
            ->map(fn ($war) => array_merge(
                ['war' => $war],
                $this->clan->warSummary($war)
            ));
    }

    /** The leader role value, so the view can flag the leader row. */
    public function getIsLeaderRole(): string
    {
        return ClanRole::Leader->value;
    }

    public function render()
    {
        return view('livewire.clan-show')->layout('layouts.app');
    }
}
