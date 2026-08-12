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
use Illuminate\Support\Facades\DB;
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

    // Retype-to-confirm for disband, matching the account-deletion pattern in Settings.
    public string $confirmDisbandName = '';

    // Edit-identity form (leader only), seeded by startEditClan(). Separate from the new*
    // fields so an abandoned edit cannot leak into the create form.
    public bool $editing = false;

    public string $editName = '';

    public string $editTag = '';

    public string $editEmblem = ClanEmblem::DEFAULT_ICON;

    public string $editEmblemColor = ClanEmblem::DEFAULT_COLOR;

    public string $editDescription = '';

    /** Open on your own clan if you have one, otherwise the Browse tab. */
    public function mount(): void
    {
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
     * #[Computed] caches per request and the view renders AFTER the action, so any action
     * touching clan_members must call this or re-render stale pre-change values.
     */
    private function forgetClanCache(): void
    {
        unset(
            $this->myMembership,
            $this->myClan,
            $this->myClanMembers,
            $this->pendingRequests,
            $this->browseClans,
            // Derived from the role, so a transfer that demotes me must drop these too.
            $this->canManageMembers,
            $this->canManageClan,
        );
    }

    /** Create a new clan (if you're not already in one) and make the creator its leader. */
    public function createClan(): void
    {
        if ($this->myClan) {
            return;
        }

        // Trim into the PROPERTIES, so validation sees the value that gets stored.
        // Validating the raw input instead lets "  ab  " pass min:3 and save as "ab", and
        // lets a name that collides only once trimmed reach the DB constraint as a 500.
        $this->newName = trim($this->newName);
        $this->newTag = trim($this->newTag);
        $this->newDescription = trim($this->newDescription);

        $this->validate(ClanEmblem::identityRules([
            'name' => 'newName',
            'tag' => 'newTag',
            'emblem' => 'newEmblem',
            'color' => 'newEmblemColor',
            'description' => 'newDescription',
        ]), [], ['newName' => __('clan.attr.name'), 'newTag' => __('clan.attr.tag')]);

        $clan = Clan::create([
            'name' => $this->newName,
            'tag' => $this->newTag ?: null,
            'emblem' => $this->newEmblem,
            'emblem_color' => $this->newEmblemColor,
            'description' => $this->newDescription ?: null,
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

        // populated(): a clan with no active members has nobody who can approve, so the
        // request would sit pending forever. Re-checked server-side, since Browse merely
        // hides such clans from the list.
        $clan = Clan::populated()->find($clanId);
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

    /**
     * Withdraw my own pending join request; without it, an applicant to an inactive clan
     * is stuck until a leader acts.
     *
     * Scoped to Auth::id() AND Pending: never someone else's request, and never a back
     * door out of an active membership (that is leaveClan, with its own leader guard).
     */
    public function cancelJoinRequest(int $clanId): void
    {
        $request = ClanMember::where('clan_id', $clanId)
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Pending)
            ->first();

        if (! $request) {
            return;
        }

        $leaderId = $request->clan?->leader_id;

        $request->delete();

        $this->forgetClanCache();

        // Silent refresh so a leader looking at the pending list sees it disappear.
        if ($leaderId) {
            $this->notify($leaderId);
        }
    }

    /** Leader-only: approve a pending join request, enforcing the member cap. */
    public function approveMember(int $clanMemberId): void
    {
        $member = $this->pendingForMyLeadership($clanMemberId);
        if (! $member) {
            return;
        }

        if ($member->clan->activeMembers()->count() >= self::MAX_MEMBERS) {
            // Its own key, NOT 'newName' (the create-clan form field on another tab).
            // Sharing a key would leak the capacity error into the wrong place and vice
            // versa; rendered near the pending list, where this action happens.
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

    /**
     * Remove an active member. Leader and co-leaders may kick, but only DOWNWARD:
     * a co-leader cannot kick the leader or a fellow co-leader.
     */
    public function kickMember(int $clanMemberId): void
    {
        if (! $this->canManageMembers) {
            return;
        }

        $member = ClanMember::where('id', $clanMemberId)
            ->where('clan_id', $this->myClan->id)
            ->where('status', ClanMemberStatus::Active)
            ->first();

        // Nobody can kick themselves -- a leader must transfer or disband, a co-leader
        // or member leaves via leaveClan.
        if (! $member || $member->user_id === Auth::id()) {
            return;
        }

        // Rank gate: you may only act on someone strictly below you. Without this a
        // co-leader could remove the leader and take the clan hostage.
        if ($member->role->rank() <= $this->myMembership->role->rank()) {
            return;
        }

        $userId = $member->user_id;
        $member->delete();

        $this->forgetClanCache();

        $this->notify($userId);
    }

    /** Leave your clan; blocked for the leader (they must transfer or disband first). */
    public function leaveClan(): void
    {
        $membership = $this->myMembership;
        if (! $membership) {
            return;
        }

        // The leader must transfer leadership or disband first. A clan whose leader
        // simply walked out would have no one able to approve, kick or disband it.
        if ($membership->role === ClanRole::Leader) {
            return;
        }

        $leaderId = $membership->clan->leader_id;
        $membership->delete();

        $this->forgetClanCache();

        $this->notify($leaderId);
    }

    /**
     * Leader-only: hand the clan to an active member, stepping down to member yourself.
     *
     * The pivot role and clans.leader_id move in ONE transaction: role drives permissions,
     * leader_id addresses clan-war notifications, and a partial update leaves a clan whose
     * leader by one measure is a plain member by the other.
     */
    public function transferLeadership(int $clanMemberId): void
    {
        if (! $this->canManageClan) {
            return;
        }

        $me = $this->myMembership;
        $clan = $this->myClan;

        $target = ClanMember::where('id', $clanMemberId)
            ->where('clan_id', $clan->id)
            ->where('status', ClanMemberStatus::Active)
            ->first();

        if (! $target || $target->user_id === Auth::id()) {
            return;
        }

        DB::transaction(function () use ($clan, $me, $target) {
            $target->update(['role' => ClanRole::Leader]);
            $me->update(['role' => ClanRole::Member]);
            $clan->update(['leader_id' => $target->user_id]);
        });

        $this->forgetClanCache();

        $this->notify($target->user_id, [
            'type' => 'accepted',
            'message' => __('clan.notify.leadership_received', ['clan' => $clan->name]),
        ]);
    }

    /**
     * Load the clan's identity into the edit form and open it.
     *
     * Seeded on open, not bound to the clan: the fields double as the live preview, so
     * binding would make an abandoned edit look applied.
     */
    public function startEditClan(): void
    {
        if (! $this->canManageClan) {
            return;
        }

        $clan = $this->myClan;

        $this->editName = $clan->name;
        $this->editTag = (string) $clan->tag;
        $this->editEmblem = ClanEmblem::isValidIcon($clan->emblem) ? $clan->emblem : ClanEmblem::DEFAULT_ICON;
        $this->editEmblemColor = ClanEmblem::isValidColor($clan->emblem_color) ? $clan->emblem_color : ClanEmblem::DEFAULT_COLOR;
        $this->editDescription = (string) $clan->description;

        $this->resetValidation();
        $this->editing = true;
    }

    /** Close the edit form, discarding whatever was typed. */
    public function cancelEditClan(): void
    {
        $this->editing = false;
        $this->resetValidation();
    }

    /**
     * Leader-only: save the clan's identity (name, tag, emblem, colour, description).
     *
     * Shares its rules with createClan via ClanEmblem::identityRules, passing the clan id
     * so the unique-name check ignores this clan -- otherwise saving the form without
     * touching the name would collide with itself.
     */
    public function saveClanIdentity(): void
    {
        if (! $this->canManageClan) {
            return;
        }

        $clan = $this->myClan;

        // Trim into the properties before validating, for the same reason createClan
        // does: a padded name must not pass min:3 and then be stored shorter.
        $this->editName = trim($this->editName);
        $this->editTag = trim($this->editTag);
        $this->editDescription = trim($this->editDescription);

        $this->validate(ClanEmblem::identityRules([
            'name' => 'editName',
            'tag' => 'editTag',
            'emblem' => 'editEmblem',
            'color' => 'editEmblemColor',
            'description' => 'editDescription',
        ], $clan->id), [], ['editName' => __('clan.attr.name'), 'editTag' => __('clan.attr.tag')]);

        $clan->update([
            'name' => $this->editName,
            'tag' => $this->editTag ?: null,
            'emblem' => $this->editEmblem,
            'emblem_color' => $this->editEmblemColor,
            'description' => $this->editDescription ?: null,
        ]);

        $this->editing = false;

        $this->forgetClanCache();

        // The roster sees the clan's name and badge, so tell them it changed.
        $others = $clan->activeMembers()
            ->where('user_id', '!=', Auth::id())
            ->pluck('user_id');

        foreach ($others as $userId) {
            $this->notify($userId, [
                'type' => 'accepted',
                'message' => __('clan.notify.identity_updated', ['clan' => $clan->name]),
            ]);
        }
    }

    /** Leader-only: raise an active member to co-leader. */
    public function promoteMember(int $clanMemberId): void
    {
        $this->changeRole($clanMemberId, ClanRole::Member, ClanRole::CoLeader, 'clan.notify.promoted');
    }

    /** Leader-only: return a co-leader to plain member. */
    public function demoteMember(int $clanMemberId): void
    {
        $this->changeRole($clanMemberId, ClanRole::CoLeader, ClanRole::Member, 'clan.notify.demoted');
    }

    /**
     * Shared promote/demote body. `$from` is asserted, not just used for lookup, so a
     * stale page cannot demote a plain member or promote someone already co-leader.
     */
    private function changeRole(int $clanMemberId, ClanRole $from, ClanRole $to, string $messageKey): void
    {
        if (! $this->canManageClan) {
            return;
        }

        $clan = $this->myClan;

        $target = ClanMember::where('id', $clanMemberId)
            ->where('clan_id', $clan->id)
            ->where('status', ClanMemberStatus::Active)
            ->where('role', $from)
            ->first();

        if (! $target || $target->user_id === Auth::id()) {
            return;
        }

        $target->update(['role' => $to]);

        $this->forgetClanCache();

        $this->notify($target->user_id, [
            'type' => 'accepted',
            'message' => __($messageKey, ['clan' => $clan->name]),
        ]);
    }

    /**
     * Leader-only: permanently delete the clan, confirmed by retyping its name.
     *
     * Blocked mid-war: dissolving would dodge an Elo loss for free, and the cascade would
     * take the opponent's war record with it.
     */
    public function disbandClan(): void
    {
        if (! $this->canManageClan) {
            return;
        }

        $clan = $this->myClan;

        if ($clan->activeWar() !== null) {
            $this->addError('disband', __('clan.error.disband_during_war'));

            return;
        }

        if (trim($this->confirmDisbandName) !== $clan->name) {
            $this->addError('disband', __('clan.error.disband_name_mismatch'));

            return;
        }

        // Everyone except the leader is told BEFORE the rows disappear -- afterwards
        // there is no roster left to address.
        $memberUserIds = $clan->activeMembers()
            ->where('user_id', '!=', Auth::id())
            ->pluck('user_id');

        $clanName = $clan->name;

        DB::transaction(function () use ($clan) {
            $clan->members()->delete();
            $clan->delete();
        });

        $this->confirmDisbandName = '';
        $this->tab = 'browse';

        $this->forgetClanCache();

        foreach ($memberUserIds as $userId) {
            $this->notify($userId, [
                'type' => 'request',
                'message' => __('clan.notify.disbanded', ['clan' => $clanName]),
            ]);
        }
    }

    /**
     * Roster powers (approve, reject, kick): leader and co-leaders.
     *
     * Public so the view gates on the SAME predicate the actions do; a blade re-deriving
     * this from the role could drift from the server gate.
     */
    #[Computed]
    public function canManageMembers(): bool
    {
        return $this->myClan && $this->myMembership->role->canManageMembers();
    }

    /** Ownership powers (promote, demote, transfer, disband, edit): leader only. */
    #[Computed]
    public function canManageClan(): bool
    {
        return $this->myClan && $this->myMembership->role->canManageClan();
    }

    /** A pending ClanMember row for my clan; leader and co-leaders may approve/reject. */
    private function pendingForMyLeadership(int $clanMemberId): ?ClanMember
    {
        if (! $this->canManageMembers) {
            return null;
        }

        return ClanMember::where('id', $clanMemberId)
            ->where('clan_id', $this->myClan->id)
            ->where('status', ClanMemberStatus::Pending)
            ->first();
    }

    /** Broadcast a clan update to one user (null notification = silent UI refresh). */
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

    /** My active clan membership (with its clan), or null if I'm in none. */
    #[Computed]
    public function myMembership(): ?ClanMember
    {
        return ClanMember::with('clan')
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Active)
            ->first();
    }

    /** The clan I'm an active member of, or null. */
    #[Computed]
    public function myClan(): ?Clan
    {
        return $this->myMembership?->clan;
    }

    /**
     * Active roster with the context a leader needs to judge each row: presence, war
     * contribution, join date.
     *
     * `contribution` is null (not 0.0) when the clan has never finished a war, so the view
     * never accuses a member of idling in a war that never ran.
     */
    #[Computed]
    public function myClanMembers()
    {
        if (! $this->myClan) {
            return collect();
        }

        // Fetched once for the whole roster; a per-member lookup would be 20 extra
        // queries on a full clan.
        $lastWar = $this->myClan->lastFinishedWar();
        $contributions = $this->myClan->warContributions($lastWar);

        return $this->myClan->orderedActiveMembers()->map(fn (ClanMember $member) => [
            'member' => $member,
            'user' => $member->user,
            'online' => $member->user->isOnline(),
            'contribution' => $lastWar ? ($contributions[$member->user_id] ?? 0.0) : null,
            'joined_at' => $member->created_at,
        ]);
    }

    /** Pending join requests for my clan -- leader and co-leaders; empty otherwise. */
    #[Computed]
    public function pendingRequests()
    {
        if (! $this->canManageMembers) {
            return collect();
        }

        return ClanMember::with('user')
            ->where('clan_id', $this->myClan->id)
            ->where('status', ClanMemberStatus::Pending)
            ->latest()
            ->get();
    }

    /** Search results (name match, capped at 20) tagged with my relation to each clan. */
    #[Computed]
    public function browseClans()
    {
        $term = trim($this->search);

        // populated(): a clan with no active members is a ghost -- nobody there to
        // approve a join request, so offering a Join button would be a dead end.
        $query = Clan::populated()->withCount(['activeMembers as members_count']);

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
