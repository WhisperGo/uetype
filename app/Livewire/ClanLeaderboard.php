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
    /**
     * Ranks all clans by highest power (Elo), together with their active-member
     * count and war-win count. Read-only.
     */
    public function getRankingProperty()
    {
        $clans = Clan::withCount('activeMembers as members_count')
            ->orderByDesc('power')
            ->orderBy('name')
            ->get();

        // Hitung jumlah war yang dimenangkan tiap clan (challenger menang =
        // result 'win'; opponent menang = result 'loss') dalam satu query set.
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

    /**
     * Id clan aktif user saat ini (untuk menyorot baris clan-nya).
     */
    public function getMyClanIdProperty(): ?int
    {
        return Auth::user()?->clan?->id;
    }

    public function render()
    {
        return view('livewire.clan-leaderboard')->layout('layouts.app');
    }
}
