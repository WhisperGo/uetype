<?php

namespace App\Livewire;

use App\Enums\ClanRole;
use App\Models\Clan;
use App\Support\Locale;
use App\Support\UsernameRules;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

#[Layout('layouts.app')]
/**
 * The account settings page: lets the signed-in user change their username
 * (with a confirmation guard) and manage account-level preferences.
 */
class Settings extends Component
{
    public string $username = '';

    public string $confirmUsername = '';

    public function mount(): void
    {
        $this->username = Auth::user()->username;
    }

    /** Validate and persist a new username (unique, alpha_dash, 3-20 chars). */
    public function saveUsername(): void
    {
        $user = Auth::user();

        $validated = $this->validate([
            'username' => UsernameRules::rules($user->id),
        ], [
            'username.unique' => __('auth.username.taken'),
            'username.alpha_dash' => __('auth.username.format'),
        ]);

        $user->update(['username' => $validated['username']]);

        $this->dispatch('username-saved');
    }

    /** Set the UI locale (session + user preference) and reload to apply it. */
    public function setLocale(string $locale): void
    {
        if (! Locale::isSupported($locale)) {
            return;
        }

        session()->put('locale', $locale);
        Auth::user()->setPreference('locale', $locale);

        $this->redirect(route('settings'), navigate: false);
    }

    /**
     * The clan this user leads, if any -- which blocks account deletion.
     *
     * Read from the pivot role, not clans.leader_id: role is what grants the powers, so
     * checking the same field the permission gates use keeps the two from disagreeing.
     */
    #[Computed]
    public function leadsClan(): ?Clan
    {
        $membership = Auth::user()?->activeClanMembership;

        return $membership?->role === ClanRole::Leader ? $membership->clan : null;
    }

    /**
     * Permanently delete the account after the user retypes their username to confirm.
     *
     * A clan leader is refused: `clans.leader_id` cascades on user delete, so this would
     * destroy the whole clan, every membership, and -- through a second cascade -- the war
     * records of opposing clans whose power had already moved.
     *
     * Refused rather than auto-transferred: handing someone a clan they never asked to
     * lead is its own surprise. Transfer and disband both exist and are explicit choices.
     */
    public function deleteAccount(): void
    {
        $user = Auth::user();

        if ($this->confirmUsername !== $user->username) {
            $this->addError('confirmUsername', __('settings.danger.confirm_body'));

            return;
        }

        if ($this->leadsClan) {
            $this->addError('confirmUsername', __('settings.danger.leads_clan', [
                'clan' => $this->leadsClan->name,
            ]));

            return;
        }

        Auth::logout();

        $user->delete();

        session()->invalidate();
        session()->regenerateToken();

        $this->redirect('/', navigate: false);
    }

    public function render()
    {
        return view('livewire.settings');
    }
}
