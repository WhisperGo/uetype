<?php

namespace App\Livewire;

use App\Enums\ClanWarStatus;
use App\Models\Clan;
use App\Models\ClanWar as ClanWarModel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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

        $wins = $this->winsPerClan();

        return $clans->map(fn (Clan $clan) => [
            'clan' => $clan,
            'wins' => $wins[$clan->id] ?? 0,
        ]);
    }

    /**
     * Wars won, keyed by clan id, counted BY THE DATABASE.
     *
     * `result` is written from the challenger's point of view, so a win belongs to the
     * challenger when it reads 'win' and to the opponent when it reads 'loss'. That is two
     * different group-by columns over the same table, which is why it is two queries rather
     * than one -- and two is the whole cost, whatever the history looks like.
     *
     * What this replaces mattered more than the query count suggests: the old version ran a
     * single query too, but it was `->get()` over EVERY finished war, counted with a foreach
     * in PHP. One query that hydrates the whole table still grows forever, and it grew on the
     * page a clan opens to check its standing. Aggregating in the database bounds the result
     * by the number of clans instead -- the same set already being rendered.
     *
     * @return array<int, int>
     */
    private function winsPerClan(): array
    {
        $countBy = fn (string $column, string $result) => ClanWarModel::query()
            ->where('status', ClanWarStatus::Finished)
            ->where('result', $result)
            ->groupBy($column)
            ->pluck(DB::raw('COUNT(*)'), $column);

        $asChallenger = $countBy('challenger_clan_id', 'win');
        $asOpponent = $countBy('opponent_clan_id', 'loss');

        $wins = [];

        foreach ([$asChallenger, $asOpponent] as $tally) {
            foreach ($tally as $clanId => $count) {
                // A disbanded clan leaves its side null (the war row outlives it). Skipped
                // rather than cast, because (int) null is 0 and would invent a clan 0.
                if ($clanId === null) {
                    continue;
                }

                $wins[(int) $clanId] = ($wins[(int) $clanId] ?? 0) + (int) $count;
            }
        }

        return $wins;
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
