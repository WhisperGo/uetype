<?php

namespace App\Livewire;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Events\ClanUpdated;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Support\ClanEmblem;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Clans hub: view your own clan, browse/search other clans, and create a new
 * one. Enforces the active-member cap and handles join/leave/create flows.
 */
class Clans extends Component
{
    // Batas member aktif per clan.
    public const MAX_MEMBERS = 20;

    // Tab aktif: 'my-clan' | 'browse' | 'create'
    public string $tab = 'my-clan';

    // Kotak pencarian nama clan (tab Browse).
    public string $search = '';

    // Form buat clan (tab Create).
    public string $newName = '';

    public string $newTag = '';

    public string $newEmblem = ClanEmblem::DEFAULT_ICON;

    public string $newEmblemColor = ClanEmblem::DEFAULT_COLOR;

    public string $newDescription = '';

    public function mount(): void
    {
        // Tab default: clan sendiri kalau sudah punya, kalau belum ke Browse.
        $this->tab = $this->myClan ? 'my-clan' : 'browse';
    }

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['my-clan', 'browse', 'create'], true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Listener Echo clan.{me}: perubahan datang dari user LAIN (mis. leader menyetujui
     * permintaan gabung saya), jadi cache computed harus dibuang -- kalau tidak,
     * re-render-nya cuma memutar ulang data lama dari request ini.
     */
    #[On('clan-updated')]
    public function refreshClan(): void
    {
        $this->forgetClanCache();
    }

    // ---- AKSI ----

    /**
     * Buang cache computed setelah keanggotaan berubah.
     *
     * #[Computed] menge-cache per REQUEST, dan view dirender SESUDAH aksi jalan --
     * jadi tanpa ini, aksi yang mengubah keanggotaan (buat/gabung/keluar/approve)
     * akan dirender ulang memakai nilai basi dari sebelum perubahan. Harus dipanggil
     * di setiap aksi yang menyentuh clan_members.
     */
    private function forgetClanCache(): void
    {
        unset($this->myMembership, $this->myClan, $this->myClanMembers, $this->pendingRequests, $this->browseClans);
    }

    public function createClan(): void
    {
        if ($this->myClan) {
            return;
        }

        // Trim ke PROPERTI sebelum validate, bukan ke variabel lokal terpisah.
        // Dulu validate() menilai $this->newName mentah tapi Clan::create menyimpan
        // versi ter-trim -> nama "  ab  " (6 char) lolos min:3 lalu tersimpan "ab"
        // (2 char). Lebih buruk: unique menilai string ber-spasi, jadi nama yang
        // ter-trim bentrok dengan clan yang sudah ada lolos validasi lalu menabrak
        // constraint DB -> 500. Menilai nilai final menutup keduanya.
        $this->newName = trim($this->newName);
        $this->newTag = trim($this->newTag);
        $this->newDescription = trim($this->newDescription);

        $this->validate([
            'newName' => ['required', 'string', 'min:3', 'max:40', 'unique:clans,name'],
            'newTag' => ['nullable', 'string', 'max:6'],
            'newEmblem' => ['required', 'string', Rule::in(ClanEmblem::iconKeys())],
            'newEmblemColor' => ['required', 'string', Rule::in(ClanEmblem::colorKeys())],
            'newDescription' => ['nullable', 'string', 'max:160'],
        ], [], ['newName' => __('clan.attr.name'), 'newTag' => __('clan.attr.tag')]);

        $clan = Clan::create([
            'name' => $this->newName,
            'tag' => $this->newTag ?: null,
            'emblem' => $this->newEmblem,
            'emblem_color' => $this->newEmblemColor,
            'description' => $this->newDescription ?: null,
            'leader_id' => Auth::id(),
        ]);

        // Pembuat langsung jadi member aktif berperan leader.
        ClanMember::create([
            'clan_id' => $clan->id,
            'user_id' => Auth::id(),
            'role' => ClanRole::Leader,
            'status' => ClanMemberStatus::Active,
        ]);

        $this->newName = '';
        $this->newTag = '';
        $this->newDescription = '';
        $this->newEmblem = ClanEmblem::DEFAULT_ICON;
        $this->newEmblemColor = ClanEmblem::DEFAULT_COLOR;
        $this->tab = 'my-clan';

        $this->forgetClanCache();
    }

    public function sendJoinRequest(int $clanId): void
    {
        if ($this->myClan) {
            return;
        }

        // Cegah kirim ulang kalau sudah ada baris ke clan ini.
        $exists = ClanMember::where('clan_id', $clanId)
            ->where('user_id', Auth::id())
            ->exists();

        if ($exists) {
            return;
        }

        $clan = Clan::find($clanId);
        if (! $clan) {
            return;
        }

        ClanMember::create([
            'clan_id' => $clanId,
            'user_id' => Auth::id(),
            'role' => ClanRole::Member,
            'status' => ClanMemberStatus::Pending,
        ]);

        $this->forgetClanCache();

        $this->notify($clan->leader_id, [
            'type' => 'request',
            'message' => __('clan.notify.join_request', ['name' => Auth::user()->username, 'clan' => $clan->name]),
        ]);
    }

    public function approveMember(int $clanMemberId): void
    {
        $member = $this->pendingForMyLeadership($clanMemberId);
        if (! $member) {
            return;
        }

        if ($member->clan->activeMembers()->count() >= self::MAX_MEMBERS) {
            // Key sendiri, BUKAN 'newName' (field form buat-clan di tab lain).
            // Berbagi key membuat error kapasitas bocor ke tempat yang salah dan
            // sebaliknya; dirender di dekat daftar pending, tempat aksi ini terjadi.
            $this->addError('approveMember', __('clan.error.max_members', ['max' => self::MAX_MEMBERS]));

            return;
        }

        $member->update(['status' => ClanMemberStatus::Active]);

        $this->forgetClanCache();

        $this->notify($member->user_id, [
            'type' => 'accepted',
            'message' => __('clan.notify.join_accepted', ['clan' => $member->clan->name]),
        ]);
    }

    public function rejectMember(int $clanMemberId): void
    {
        $member = $this->pendingForMyLeadership($clanMemberId);
        if (! $member) {
            return;
        }

        $userId = $member->user_id;
        $member->delete();

        $this->forgetClanCache();

        $this->notify($userId);
    }

    public function kickMember(int $clanMemberId): void
    {
        $member = ClanMember::where('id', $clanMemberId)
            ->where('status', ClanMemberStatus::Active)
            ->whereHas('clan', fn ($q) => $q->where('leader_id', Auth::id()))
            ->first();

        // Leader tak bisa mengeluarkan dirinya sendiri.
        if (! $member || $member->user_id === Auth::id()) {
            return;
        }

        $userId = $member->user_id;
        $member->delete();

        $this->forgetClanCache();

        $this->notify($userId);
    }

    public function leaveClan(): void
    {
        $membership = $this->myMembership;
        if (! $membership) {
            return;
        }

        // Leader harus membubarkan/transfer clan dulu; leave diblokir untuk leader.
        if ($membership->role === ClanRole::Leader) {
            return;
        }

        $leaderId = $membership->clan->leader_id;
        $membership->delete();

        $this->forgetClanCache();

        $this->notify($leaderId);
    }

    /** Baris ClanMember pending milik clan yang dipimpin user ini; hanya leader boleh approve/reject. */
    private function pendingForMyLeadership(int $clanMemberId): ?ClanMember
    {
        return ClanMember::where('id', $clanMemberId)
            ->where('status', ClanMemberStatus::Pending)
            ->whereHas('clan', fn ($q) => $q->where('leader_id', Auth::id()))
            ->first();
    }

    private function notify(int $otherUserId, ?array $notification = null): void
    {
        SafeBroadcast::run(fn () => broadcast(new ClanUpdated($otherUserId, $notification)));
    }

    // ---- DATA (computed) ----
    //
    // #[Computed] penting di sini, bukan kosmetik: getter gaya lama
    // (getMyMembershipProperty) TIDAK di-cache Livewire, jadi query yang sama
    // dijalankan ulang tiap kali propertinya dibaca. myMembership dibaca dari
    // mount(), createClan(), myClan, myClanMembers, pendingRequests, dan view --
    // 5-6 query identik per render. #[Computed] menge-cache-nya per request.
    // Nama akses di view tak berubah ($this->myClan), jadi tak ada view yang perlu disentuh.

    #[Computed]
    public function myMembership(): ?ClanMember
    {
        return ClanMember::with('clan')
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Active)
            ->first();
    }

    #[Computed]
    public function myClan(): ?Clan
    {
        return $this->myMembership?->clan;
    }

    #[Computed]
    public function myClanMembers()
    {
        if (! $this->myClan) {
            return collect();
        }

        return $this->myClan->activeMembers()->with('user')->orderBy('role')->get();
    }

    #[Computed]
    public function pendingRequests()
    {
        if (! $this->myClan || $this->myMembership->role !== ClanRole::Leader) {
            return collect();
        }

        return ClanMember::with('user')
            ->where('clan_id', $this->myClan->id)
            ->where('status', ClanMemberStatus::Pending)
            ->latest()
            ->get();
    }

    #[Computed]
    public function browseClans()
    {
        $term = trim($this->search);

        $query = Clan::withCount(['activeMembers as members_count']);

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        $clans = $query->orderBy('name')->limit(20)->get();

        // Status keanggotaan SAYA untuk semua clan sekaligus (dulu: satu query per
        // baris -> 20 clan = 20 query hanya untuk memilih label tombolnya).
        $myMemberships = ClanMember::where('user_id', Auth::id())
            ->whereIn('clan_id', $clans->pluck('id'))
            ->get()
            ->keyBy('clan_id');

        return $clans->map(function (Clan $clan) use ($myMemberships) {
            $membership = $myMemberships->get($clan->id);

            $relation = match (true) {
                $membership === null => 'none',
                $membership->status === ClanMemberStatus::Active => 'member',
                default => 'pending',
            };

            return ['clan' => $clan, 'relation' => $relation];
        });
    }

    public function render()
    {
        return view('livewire.clans')->layout('layouts.app');
    }
}
