<?php

namespace App\Livewire;

use App\Support\Locale;
use App\Support\UsernameRules;
use Illuminate\Support\Facades\Auth;
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

    public function setLocale(string $locale): void
    {
        if (! Locale::isSupported($locale)) {
            return;
        }

        session()->put('locale', $locale);
        Auth::user()->setPreference('locale', $locale);

        $this->redirect(route('settings'), navigate: false);
    }

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
