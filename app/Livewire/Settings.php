<?php

namespace App\Livewire;

use App\Enums\ClanMemberStatus;
use App\Enums\ClanRole;
use App\Models\Clan;
use App\Models\ClanMember;
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
     * True if this user leads a clan, which blocks account deletion.
     *
     * Read from the pivot role rather than clans.leader_id: role is what grants the
     * powers, and checking the same field the permission gates use means the two can
     * never disagree about who is a leader.
     */
    #[Computed]
    public function leadsClan(): ?Clan
    {
        $membership = ClanMember::with('clan')
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Active)
            ->where('role', ClanRole::Leader)
            ->first();

        return $membership?->clan;
    }

    /**
     * Permanently delete the account after the user retypes their username to confirm.
     *
     * A clan leader is refused. `clans.leader_id` cascades on user delete, so deleting a
     * leader's account silently destroyed the whole clan -- every membership row with it,
     * and (through a second cascade) the war records of the OPPOSING clans, whose power
     * had already moved. That is a lot of other people's data destroyed as a side effect
     * of one person leaving.
     *
     * Refusing is deliberate rather than auto-transferring to the longest-serving member:
     * silently handing someone a clan they never asked to lead is its own surprise. The
     * leader is pointed at transfer or disband, both of which now exist, and both of
     * which are an explicit choice.
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
