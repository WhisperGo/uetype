<?php

namespace App\Livewire;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\ClanWarModeClaim;
use App\Services\ClanWarModeCatalog;
use App\Services\ClanWarResolver;
use App\Support\SafeBroadcast;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

class ClanWar extends Component
{
    // Durasi war setelah tantangan diterima.
    public const WAR_DURATION_DAYS = 3;

    // Batas waktu tantangan harus di-accept sebelum otomatis hangus.
    public const ACCEPT_WINDOW_HOURS = 1;

    /** Tutup war lewat-waktu (expire Pending, resolve Ongoing) sebelum computed property membaca data. */
    public function mount(ClanWarResolver $resolver): void
    {
        $resolver->resolveDue();
    }

    // ---- DATA (computed) ----

    public function getMyMembershipProperty(): ?ClanMember
    {
        return ClanMember::with('clan')
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Active)
            ->first();
    }

    public function getMyClanProperty(): ?Clan
    {
        return $this->myMembership?->clan;
    }

    public function getIsLeaderProperty(): bool
    {
        return $this->myMembership?->role === ClanRole::Leader;
    }

    public function getMyActiveWarProperty(): ?ClanWarModel
    {
        return $this->myClan?->activeWar();
    }

    /** Tantangan masuk (war Pending, clan kita opponent); hanya relevan untuk leader. */
    public function getIncomingChallengeProperty(): ?ClanWarModel
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Pending || ! $this->myClan) {
            return null;
        }

        return $war->opponent_clan_id === $this->myClan->id ? $war : null;
    }

    /** Clan lain yang bebas ditantang; hanya untuk leader yang clan-nya sendiri juga bebas. */
    public function getChallengeableClansProperty()
    {
        if (! $this->isLeader || $this->myActiveWar) {
            return collect();
        }

        return Clan::where('id', '!=', $this->myClan->id)
            ->orderByDesc('power')
            ->get()
            ->filter(fn (Clan $clan) => $clan->activeWar() === null)
            ->values();
    }

    /** Riwayat war sendiri yang sudah selesai, terbaru dulu. */
    public function getWarHistoryProperty()
    {
        if (! $this->myClan) {
            return collect();
        }

        return ClanWarModel::with(['challenger', 'opponent'])
            ->where(function ($q) {
                $q->where('challenger_clan_id', $this->myClan->id)
                    ->orWhere('opponent_clan_id', $this->myClan->id);
            })
            ->where('status', ClanWarStatus::Finished)
            ->latest('updated_at')
            ->take(5)
            ->get();
    }

    /**
     * Grid 9 mode wajib untuk war Ongoing. Tiap entri: mode/config/ceiling + status
     * ('open' | 'claimed' | 'done') plus baris klaim kalau ada.
     */
    public function getModeGridProperty()
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Ongoing || ! $this->myClan) {
            return collect();
        }

        // Ambil semua klaim sekaligus (hindari N query); typingResult eager-load untuk
        // kartu mode yang sudah selesai.
        $claims = ClanWarModeClaim::with(['user', 'typingResult'])
            ->where('clan_war_id', $war->id)
            ->where('clan_id', $this->myClan->id)
            ->get()
            ->keyBy(fn (ClanWarModeClaim $c) => $c->mode.'|'.$c->mode_config);

        return collect(ClanWarModeCatalog::MODES)->map(function (array $m) use ($claims) {
            $claim = $claims->get($m['mode'].'|'.$m['config']);

            $status = 'open';
            if ($claim) {
                $status = $claim->isSubmitted() ? 'done' : 'claimed';
            }

            return [
                'mode' => $m['mode'],
                'config' => $m['config'],
                'ceiling' => $m['ceiling'],
                'status' => $status,
                'claim' => $claim,
            ];
        });
    }

    /** Total poin clan sendiri sejauh ini (hanya mode yang sudah disubmit). */
    public function getMyClanPointsProperty(): float
    {
        $war = $this->myActiveWar;

        if (! $war || ! $this->myClan) {
            return 0.0;
        }

        return (float) ClanWarModeClaim::where('clan_war_id', $war->id)
            ->where('clan_id', $this->myClan->id)
            ->whereNotNull('typing_result_id')
            ->sum('points');
    }

    // ---- AKSI ----

    /**
     * Klaim salah satu dari 9 mode, lalu arahkan ke typing engine dengan mode terkunci.
     * Unique constraint DB jadi jaring pengaman terakhir untuk race dua member klaim bersamaan.
     */
    public function claimMode(string $mode, string $config): void
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Ongoing || ! $this->myClan) {
            return;
        }

        if (! ClanWarModeCatalog::isValidMode($mode, $config)) {
            return;
        }

        try {
            $claim = DB::transaction(function () use ($war, $mode, $config) {
                // Cek dulu clan ini belum mengklaim slot ini.
                $existing = ClanWarModeClaim::where('clan_war_id', $war->id)
                    ->where('clan_id', $this->myClan->id)
                    ->where('mode', $mode)
                    ->where('mode_config', $config)
                    ->first();

                if ($existing) {
                    return null;
                }

                return ClanWarModeClaim::create([
                    'clan_war_id' => $war->id,
                    'clan_id' => $this->myClan->id,
                    'user_id' => Auth::id(),
                    'mode' => $mode,
                    'mode_config' => $config,
                    'claimed_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // Unique violation: member lain baru saja mengambil slot ini.
            $claim = null;
        }

        if (! $claim) {
            session()->flash('clan_war_claim_error', 'Mode ini baru saja diambil oleh member lain.');

            return;
        }

        $this->redirect(route('typing', ['war_claim' => $claim->id]), navigate: true);
    }

    /** Batalkan klaim belum-disubmit (mode kembali kosong); pengklaim sendiri atau leader. */
    public function cancelClaim(int $claimId): void
    {
        if (! $this->myClan) {
            return;
        }

        $claim = ClanWarModeClaim::where('id', $claimId)
            ->where('clan_id', $this->myClan->id)
            ->whereNull('typing_result_id')
            ->first();

        if (! $claim) {
            return;
        }

        // Hanya pengklaim atau leader yang boleh membatalkan.
        if ($claim->user_id !== Auth::id() && ! $this->isLeader) {
            return;
        }

        $claim->delete();
    }

    public function challengeClan(int $opponentClanId): void
    {
        if (! $this->isLeader || $this->myActiveWar) {
            return;
        }

        $opponent = Clan::find($opponentClanId);

        // Re-validasi server-side: jangan percaya daftar yang tampil di client.
        if (! $opponent || $opponent->id === $this->myClan->id || $opponent->activeWar() !== null) {
            return;
        }

        $war = ClanWarModel::create([
            'challenger_clan_id' => $this->myClan->id,
            'opponent_clan_id' => $opponent->id,
            'status' => ClanWarStatus::Pending,
            'accept_deadline_at' => now()->addHours(self::ACCEPT_WINDOW_HOURS),
        ]);

        $this->notify($opponent->leader_id, [
            'type' => 'war-challenge',
            'message' => $this->myClan->name.' menantang clan-mu untuk Clan War',
        ]);
    }

    public function acceptChallenge(int $warId): void
    {
        $war = $this->pendingChallengeForMyLeadership($warId);
        if (! $war) {
            return;
        }

        $war->update([
            'status' => ClanWarStatus::Ongoing,
            'challenger_power_before' => $war->challenger->power,
            'opponent_power_before' => $war->opponent->power,
            'started_at' => now(),
            'ends_at' => now()->addDays(self::WAR_DURATION_DAYS),
        ]);

        $this->notify($war->challenger->leader_id, [
            'type' => 'war-accepted',
            'message' => $war->opponent->name.' menerima tantangan Clan War-mu',
        ]);
    }

    public function declineChallenge(int $warId): void
    {
        $war = $this->pendingChallengeForMyLeadership($warId);
        if (! $war) {
            return;
        }

        $war->update(['status' => ClanWarStatus::Declined]);

        $this->notify($war->challenger->leader_id, [
            'type' => 'war-declined',
            'message' => $war->opponent->name.' menolak tantangan Clan War-mu',
        ]);
    }

    /** War Pending di mana clan kita adalah opponent & kita leadernya. */
    private function pendingChallengeForMyLeadership(int $warId): ?ClanWarModel
    {
        if (! $this->isLeader || ! $this->myClan) {
            return null;
        }

        return ClanWarModel::with(['challenger', 'opponent'])
            ->where('id', $warId)
            ->where('opponent_clan_id', $this->myClan->id)
            ->where('status', ClanWarStatus::Pending)
            ->first();
    }

    private function notify(int $otherUserId, ?array $notification = null): void
    {
        SafeBroadcast::run(fn () => broadcast(new ClanUpdated($otherUserId, $notification)));
    }

    public function render()
    {
        return view('livewire.clan-war')->layout('layouts.app');
    }
}
