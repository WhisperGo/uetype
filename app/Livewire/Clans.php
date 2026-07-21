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
    // Active-member cap per clan.
    public const MAX_MEMBERS = 20;

    // Active tab: 'my-clan' | 'browse' | 'create'
    public string $tab = 'my-clan';

    // Clan-name search box (Browse tab).
    public string $search = '';

    // Create-clan form (Create tab).
    public string $newName = '';

    public string $newTag = '';

    public string $newEmblem = ClanEmblem::DEFAULT_ICON;

    public string $newEmblemColor = ClanEmblem::DEFAULT_COLOR;

    public string $newDescription = '';

    public function mount(): void
    {
        // Default tab: own clan if you have one, otherwise Browse.
        $this->tab = $this->myClan ? 'my-clan' : 'browse';
    }

    /** Switch the active tab (whitelisted). */
    public function setTab(string $tab): void
    {
        if (in_array($tab, ['my-clan', 'browse', 'create'], true)) {
            $this->tab = $tab;
        }
    }

    /**
     * Echo listener for clan.{me}: the change comes from ANOTHER user (e.g. a leader
     * approving my join request), so the computed cache must be dropped -- otherwise the
     * re-render just replays this request's stale data.
     */
    #[On('clan-updated')]
    public function refreshClan(): void
    {
        $this->forgetClanCache();
    }

    // ---- ACTIONS ----

    /**
     * Drop the computed cache after membership changes.
     *
     * #[Computed] caches per REQUEST, and the view renders AFTER the action runs -- so
     * without this, an action that changes membership (create/join/leave/approve) would
     * re-render with stale pre-change values. Must be called in every action that touches
     * clan_members.
     */
    private function forgetClanCache(): void
    {
        unset($this->myMembership, $this->myClan, $this->myClanMembers, $this->pendingRequests, $this->browseClans);
    }

    /** Create a new clan (if you're not already in one) and make the creator its leader. */
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
        ], [], ['newName' => __('clan.attr.name'), 'newTag' => __('clan.attr.tag')]);

        $clan = Clan::create([
            'name' => $name,
            'tag' => $tag,
            'emblem' => $this->newEmblem,
            'emblem_color' => $this->newEmblemColor,
            'description' => $description,
            'leader_id' => Auth::id(),
        ]);

        // The creator immediately becomes an active member with the leader role.
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

    /** Send a pending join request to a clan (blocked if already in a clan or already requested). */
    public function sendJoinRequest(int $clanId): void
    {
        if ($this->myClan) {
            return;
        }

        // Prevent a duplicate request if a row to this clan already exists.
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
            'message' => Auth::user()->username.' meminta bergabung ke '.$clan->name,
        ]);
    }

    /** Leader-only: approve a pending join request, enforcing the member cap. */
    public function approveMember(int $clanMemberId): void
    {
        $member = $this->pendingForMyLeadership($clanMemberId);
        if (! $member) {
            return;
        }

        if ($member->clan->activeMembers()->count() >= self::MAX_MEMBERS) {
            $this->addError('newName', __('clan.error.max_members', ['max' => self::MAX_MEMBERS]));

            return;
        }

        $member->update(['status' => ClanMemberStatus::Active]);

        $this->forgetClanCache();

        $this->notify($member->user_id, [
            'type' => 'accepted',
            'message' => 'Permintaanmu bergabung ke '.$member->clan->name.' diterima',
        ]);
    }

    /** Leader-only: reject (delete) a pending join request. */
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

        // A leader can't kick themselves.
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

        // A leader must disband/transfer the clan first; leaving is blocked for leaders.
        if ($membership->role === ClanRole::Leader) {
            return;
        }

        $leaderId = $membership->clan->leader_id;
        $membership->delete();

        $this->forgetClanCache();

        $this->notify($leaderId);
    }

    /** A pending ClanMember row for the clan this user leads; only a leader may approve/reject. */
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
    // #[Computed] matters here, it's not cosmetic: old-style getters
    // (getMyMembershipProperty) are NOT cached by Livewire, so the same query re-runs on
    // every property read. myMembership is read from mount(), createClan(), myClan,
    // myClanMembers, pendingRequests, and the view -- 5-6 identical queries per render.
    // #[Computed] caches it per request. The view access name is unchanged ($this->myClan),
    // so no view needs touching.

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

        // MY membership status for all clans at once (previously: one query per row ->
        // 20 clans = 20 queries just to pick each button's label).
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
