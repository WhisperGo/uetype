<?php

namespace App\Livewire;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Enums\ClanWarStatus;
use App\Events\ClanUpdated;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\ClanWar as ClanWarModel;
use App\Models\TypingResult;
use App\Services\ClanWarResolver;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

class ClanWar extends Component
{
    // Durasi war setelah tantangan diterima.
    public const WAR_DURATION_DAYS = 3;

    // Batas waktu tantangan harus di-accept sebelum otomatis hangus.
    public const ACCEPT_WINDOW_HOURS = 1;

    /**
     * Menutup war yang sudah lewat waktunya (expire tantangan Pending,
     * resolve war Ongoing) SEBELUM computed property manapun membaca data
     * war -- supaya halaman ini selalu menampilkan state yang sudah
     * ter-update, tanpa perlu command/scheduler terjadwal.
     */
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

    /**
     * Tantangan masuk (war Pending di mana clan kita adalah opponent) --
     * hanya relevan untuk leader.
     */
    public function getIncomingChallengeProperty(): ?ClanWarModel
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Pending || ! $this->myClan) {
            return null;
        }

        return $war->opponent_clan_id === $this->myClan->id ? $war : null;
    }

    /**
     * Clan lain yang bebas ditantang (tidak sedang war/tantangan apa pun).
     * Hanya ditampilkan untuk leader yang clan-nya sendiri juga sedang bebas.
     */
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

    /**
     * Riwayat war milik clan sendiri yang sudah selesai (menang/seri/kalah),
     * terbaru dulu.
     */
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
     * Kontribusi XP live per member clan sendiri selama war Ongoing --
     * dihitung on-the-fly (tak disimpan permanen) supaya terasa "hidup"
     * sebelum war ditutup.
     */
    public function getMyClanBreakdownProperty()
    {
        $war = $this->myActiveWar;

        if (! $war || $war->status !== ClanWarStatus::Ongoing || ! $this->myClan) {
            return collect();
        }

        return $this->myClan->activeMembers()->with('user')->get()
            ->map(function (ClanMember $member) use ($war) {
                $total = TypingResult::where('user_id', $member->user_id)
                    ->whereBetween('created_at', [$war->started_at, now()])
                    ->sum('xp_earned');

                return ['user' => $member->user, 'total' => (int) $total];
            })
            ->sortByDesc('total')
            ->values();
    }

    // ---- AKSI ----

    public function challengeClan(int $opponentClanId): void
    {
        if (! $this->isLeader || $this->myActiveWar) {
            return;
        }

        $opponent = Clan::find($opponentClanId);

        // Re-validasi server-side: jangan percaya daftar yang tampil di
        // client, cek ulang clan lawan benar-benar masih bebas war.
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
            'type' => 'request',
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
            'type' => 'accepted',
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

        $this->notify($war->challenger->leader_id);
    }

    /**
     * War Pending di mana clan kita adalah opponent DAN kita adalah
     * leadernya. Gerbang keamanan: hanya leader clan yang ditantang boleh
     * accept/decline.
     */
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
        broadcast(new ClanUpdated($otherUserId, $notification));
    }

    public function render()
    {
        return view('livewire.clan-war')->layout('layouts.app');
    }
}
