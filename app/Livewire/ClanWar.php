<?php

namespace App\Livewire;

use App\Enums\ClanWarStatus;
use App\Models\Clan;
use App\Models\ClanWar as ClanWarModel;
use App\Models\TypingResult;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ClanWar extends Component
{
    /**
     * War paling relevan untuk ditampilkan: yang sedang berjalan kalau ada,
     * kalau tidak war terakhir yang sudah selesai. Read-only -- war baru
     * hanya dibuat lewat artisan command `clan-war:start`.
     */
    public function getCurrentWarProperty(): ?ClanWarModel
    {
        return ClanWarModel::where('status', ClanWarStatus::Ongoing)->latest('starts_at')->first()
            ?? ClanWarModel::where('status', ClanWarStatus::Finished)->latest('ends_at')->first();
    }

    /**
     * Peringkat clan dalam war ini. Untuk war yang sudah Finished, angkanya
     * diambil dari clan_war_participants (hasil akhir tersimpan). Untuk war
     * yang masih Ongoing, dihitung live dari typing_results supaya peringkat
     * terkini terlihat tanpa menunggu war ditutup.
     */
    public function getStandingsProperty()
    {
        $war = $this->currentWar;
        if (! $war) {
            return collect();
        }

        if ($war->status === ClanWarStatus::Finished) {
            return $war->participants()->with('clan')->orderBy('placement')->get()
                ->map(fn ($p) => ['clan' => $p->clan, 'total' => $p->total_contribution, 'placement' => $p->placement]);
        }

        return Clan::all()->map(function ($clan) use ($war) {
            $memberIds = $clan->activeMembers()->pluck('user_id');

            $total = TypingResult::whereIn('user_id', $memberIds)
                ->whereBetween('created_at', [$war->starts_at, $war->ends_at])
                ->sum('xp_earned');

            return ['clan' => $clan, 'total' => (int) $total, 'placement' => null];
        })->sortByDesc('total')->values();
    }

    /**
     * Kontribusi per member DI DALAM clan milik user saat ini -- dihitung
     * live dari typing_results (tak perlu disimpan permanen, breakdown per
     * member hanya relevan selagi war berjalan/baru selesai).
     */
    public function getMyClanBreakdownProperty()
    {
        $war = $this->currentWar;
        $clan = Auth::user()->clan;

        if (! $war || ! $clan) {
            return collect();
        }

        return $clan->activeMembers()->with('user')->get()
            ->map(function ($member) use ($war) {
                $total = TypingResult::where('user_id', $member->user_id)
                    ->whereBetween('created_at', [$war->starts_at, $war->ends_at])
                    ->sum('xp_earned');

                return ['user' => $member->user, 'total' => (int) $total];
            })
            ->sortByDesc('total')
            ->values();
    }

    public function render()
    {
        return view('livewire.clan-war')->layout('layouts.app');
    }
}
