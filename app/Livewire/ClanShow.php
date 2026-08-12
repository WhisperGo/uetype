<?php

namespace App\Livewire;

use App\Models\Clan;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Component;

/** A single clan's detail page: profile, active members, and stats (read-only). */
class ClanShow extends Component
{
    public Clan $clan;

    public function mount(Clan $clan): void
    {
        $this->clan = $clan;
    }

    /**
     * Active members of this clan, in authority order.
     *
     * #[Computed], not a plain getter: the view reads $this->members three times (count,
     * heading, loop) and Livewire does not cache old-style getters.
     */
    #[Computed]
    public function members()
    {
        return $this->clan->orderedActiveMembers();
    }

    /**
     * Whether the viewer is an active member of THIS clan.
     *
     * Gates the clan-chat link. The page is public, and for an outsider that link would
     * resolve to their own clan or to nothing -- a link that lies about where it goes.
     * The channel itself is safe regardless: chat derives the clan from the viewer's
     * membership, never from a URL.
     */
    #[Computed]
    public function isMyClan(): bool
    {
        return Auth::check() && Auth::user()->clan?->id === $this->clan->id;
    }

    /** Finished-war history from this clan's viewpoint (result, opponent, power delta). */
    #[Computed]
    public function history()
    {
        return $this->clan->finishedWars()
            ->map(fn ($war) => array_merge(
                ['war' => $war],
                $this->clan->warSummary($war)
            ));
    }

    public function render()
    {
        return view('livewire.clan-show')->layout('layouts.app');
    }
}
