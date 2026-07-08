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
use Livewire\Attributes\On;
use Livewire\Component;

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

    /** Listener Echo clan.{me}; body kosong karena action apa pun memicu re-render. */
    #[On('clan-updated')]
    public function refreshClan(): void
    {
        //
    }

    // ---- AKSI ----

    public function createClan(): void
    {
        if ($this->myClan) {
            return;
        }

        $name = trim($this->newName);
        $tag = trim($this->newTag) ?: null;
        $description = trim($this->newDescription) ?: null;

        $this->validate([
            'newName' => ['required', 'string', 'min:3', 'max:40', 'unique:clans,name'],
            'newTag' => ['nullable', 'string', 'max:6'],
            'newEmblem' => ['required', 'string', Rule::in(ClanEmblem::iconKeys())],
            'newEmblemColor' => ['required', 'string', Rule::in(ClanEmblem::colorKeys())],
            'newDescription' => ['nullable', 'string', 'max:160'],
        ], [], ['newName' => 'nama clan', 'newTag' => 'tag clan']);

        $clan = Clan::create([
            'name' => $name,
            'tag' => $tag,
            'emblem' => $this->newEmblem,
            'emblem_color' => $this->newEmblemColor,
            'description' => $description,
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

        $this->notify($clan->leader_id, [
            'type' => 'request',
            'message' => Auth::user()->username.' meminta bergabung ke '.$clan->name,
        ]);
    }

    public function approveMember(int $clanMemberId): void
    {
        $member = $this->pendingForMyLeadership($clanMemberId);
        if (! $member) {
            return;
        }

        if ($member->clan->activeMembers()->count() >= self::MAX_MEMBERS) {
            $this->addError('newName', 'Clan sudah mencapai batas maksimal '.self::MAX_MEMBERS.' member.');

            return;
        }

        $member->update(['status' => ClanMemberStatus::Active]);

        $this->notify($member->user_id, [
            'type' => 'accepted',
            'message' => 'Permintaanmu bergabung ke '.$member->clan->name.' diterima',
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

    public function getMyClanMembersProperty()
    {
        if (! $this->myClan) {
            return collect();
        }

        return $this->myClan->activeMembers()->with('user')->orderBy('role')->get();
    }

    public function getPendingRequestsProperty()
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

    public function getBrowseClansProperty()
    {
        $term = trim($this->search);

        $query = Clan::withCount(['activeMembers as members_count']);

        if ($term !== '') {
            $query->where('name', 'like', '%'.$term.'%');
        }

        return $query->orderBy('name')->limit(20)->get()
            ->map(function (Clan $clan) {
                $membership = ClanMember::where('clan_id', $clan->id)
                    ->where('user_id', Auth::id())
                    ->first();

                $relation = 'none';
                if ($membership) {
                    $relation = $membership->status === ClanMemberStatus::Active ? 'member' : 'pending';
                }

                return ['clan' => $clan, 'relation' => $relation];
            });
    }

    public function render()
    {
        return view('livewire.clans')->layout('layouts.app');
    }
}
