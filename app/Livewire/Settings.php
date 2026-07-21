<?php

namespace App\Livewire;

use App\Support\Locale;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
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
            'username' => [
                'required',
                'string',
                'alpha_dash',
                'min:3',
                'max:20',
                Rule::unique('users', 'username')->ignore($user->id),
            ],
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

    /** Permanently delete the account after the user retypes their username to confirm. */
    public function deleteAccount(): void
    {
        $user = Auth::user();

        if ($this->confirmUsername !== $user->username) {
            $this->addError('confirmUsername', __('settings.danger.confirm_body'));

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
