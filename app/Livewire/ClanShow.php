<?php

namespace App\Livewire;

use App\Enums\ClanRole;
use App\Models\Clan;
use Illuminate\Support\Facades\Auth;
use Livewire\Component;

/** A single clan's detail page: profile, active members, and stats (read-only). */
class ClanShow extends Component
{
    public Clan $clan;

    public function mount(Clan $clan): void
    {
        $this->clan = $clan;
    }

    /** Active members of this clan, in authority order. */
    public function getMembersProperty()
    {
        return $this->clan->orderedActiveMembers();
    }

    /**
     * Whether the viewer is an active member of THIS clan.
     *
     * Gates the clan-chat link: this page is public, so it is regularly viewed by people
     * with no claim on the channel. Offering them a way in would only lead to a chat that
     * resolves to their own clan (or nothing at all) -- a link that lies about where it
     * goes. The channel itself is safe either way, since chat derives the clan from the
     * viewer's own membership rather than from any id in the URL.
     */
    public function getIsMyClanProperty(): bool
    {
        return Auth::check() && Auth::user()->clan?->id === $this->clan->id;
    }

    /**
     * This clan's finished-war history, summarized from the clan's viewpoint
     * (result, opponent, power delta) via a model helper.
     */
    public function getHistoryProperty()
    {
        return $this->clan->finishedWars()
            ->map(fn ($war) => array_merge(
                ['war' => $war],
                $this->clan->warSummary($war)
            ));
    }

    /** The leader role value, so the view can flag the leader row. */
    public function getIsLeaderRole(): string
    {
        return ClanRole::Leader->value;
    }

    public function render()
    {
        return view('livewire.clan-show')->layout('layouts.app');
    }
}
