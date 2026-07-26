<?php

namespace App\Livewire;

use App\Enums\ClanWarStatus;
use App\Models\Clan;
use App\Models\ClanWar as ClanWarModel;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/**
 * The clan leaderboard: ranks all clans by Elo power. Read-only.
 */
class ClanLeaderboard extends Component
{
    /** All clans ranked by power, with active-member and war-win counts. */
    public function getRankingProperty()
    {
        // Empty clans are left out: a ranking is a list of clans you could face, and one
        // with nobody in it can neither be challenged nor climb.
        $clans = Clan::populated()
            ->withCount('activeMembers as members_count')
            ->orderByDesc('power')
            ->orderBy('name')
            ->get();

        // Count wars won by each clan (challenger wins = result 'win'; opponent wins =
        // result 'loss') in one query set.
        $finished = ClanWarModel::where('status', ClanWarStatus::Finished)->get();

        $wins = [];
        foreach ($finished as $war) {
            if ($war->result === 'win') {
                $wins[$war->challenger_clan_id] = ($wins[$war->challenger_clan_id] ?? 0) + 1;
            } elseif ($war->result === 'loss') {
                $wins[$war->opponent_clan_id] = ($wins[$war->opponent_clan_id] ?? 0) + 1;
            }
        }

        return $clans->map(fn (Clan $clan) => [
            'clan' => $clan,
            'wins' => $wins[$clan->id] ?? 0,
        ]);
    }

    /** The current user's active clan id (to highlight their clan's row). */
    public function getMyClanIdProperty(): ?int
    {
        return Auth::user()?->clan?->id;
    }

    public function render()
    {
        return view('livewire.clan-leaderboard')->layout('layouts.app');
    }
}
