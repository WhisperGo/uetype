<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\UsernameRules;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

/**
 * The application's only authentication path: Google OAuth. The `users` table has
 * no `password` column -- Breeze's default password scaffolding was removed
 * entirely, including its login/logout pages, which now live here.
 */
class GoogleAuthController extends Controller
{
    /** Login page: offers only "continue with Google". */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    /** Redirect the user to Google's OAuth consent screen. */
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    /** OAuth callback: log in an existing user, or route a new one to username selection. */
    public function callback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            // Check whether the user already exists in the database.
            $user = User::where('google_id', $googleUser->id)
                ->orWhere('email', $googleUser->email)
                ->first();

            if ($user) {
                // Account exists: sync the Google ID, then log in.
                if (! $user->google_id) {
                    $user->update(
                        [
                            'google_id' => $googleUser->id,
                            'avatar' => $googleUser->avatar,
                        ]
                    );
                }

                $this->loginAndRegenerate($request, $user);

                return redirect()->intended('/typing');
            }

            // Account doesn't exist yet: don't persist to the DB, stash the Google data in the session.
            $request->session()->put('google_register_data', [
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]);

            return redirect()->route('auth.google.choose-username');

        } catch (\Exception $e) {
            // Without a log, an OAuth misconfig (wrong client ID, mismatched redirect
            // URI) can't be told apart from a user simply cancelling consent.
            Log::warning('Google OAuth gagal', ['exception' => $e]);

            return redirect()->route('login')->with('error', __('auth.google_failed'));
        }
    }

    /**
     * Show the username-selection form.
     */
    public function showChooseUsernameForm(Request $request)
    {
        // Requires the Google session data; without it, send back to login.
        if (! $request->session()->has('google_register_data')) {
            return redirect()->route('login');
        }

        return view('auth.google-username');
    }

    /**
     * Persist the new user after they choose a username.
     */
    public function storeUsername(Request $request)
    {
        // Require the Google data in the session.
        if (! $request->session()->has('google_register_data')) {
            return redirect()->route('login');
        }

        $googleData = $request->session()->get('google_register_data');

        // Re-check the database before insert to prevent a duplicate entry.
        $existingUser = User::where('google_id', $googleData['google_id'])
            ->orWhere('email', $googleData['email'])
            ->first();

        if ($existingUser) {
            // Data already exists: cancel registration, log in directly.
            $request->session()->forget('google_register_data');
            $this->loginAndRegenerate($request, $existingUser);

            return redirect('/typing');
        }

        // Validate the user's chosen username (must be unique). Same rules as
        // Settings::saveUsername -- one source in UsernameRules. No ignore: this user has
        // no row yet, so there's nothing to exclude from the unique check.
        $request->validate([
            'username' => UsernameRules::rules(),
        ], [
            'username.unique' => __('auth.username.taken'),
            'username.alpha_dash' => __('auth.username.format'),
        ]);

        // Create the new user. No password column -- identity rests entirely on google_id.
        $user = User::create([
            'username' => $request->username,
            'email' => $googleData['email'],
            'google_id' => $googleData['google_id'],
            'avatar' => $googleData['avatar'],
        ]);

        // Clear the Google session data.
        $request->session()->forget('google_register_data');

        // Log them in automatically and send them to the game.
        $this->loginAndRegenerate($request, $user);

        return redirect('/typing');
    }

    /** Mark the user offline, then log out and invalidate the session. */
    public function logout(Request $request): RedirectResponse
    {
        // Mark offline before logout so friends see the offline status immediately.
        Auth::user()?->markOffline();

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Log in + rotate the session ID. Auth::login() does NOT rotate the session ID
     * itself, so without regenerate() a session held from before login stays valid
     * afterward -- anyone who managed to plant a session ID in the victim's browser
     * (shared machine, lab) is carried in too. Every login path must go through
     * here; never call Auth::login() directly.
     */
    private function loginAndRegenerate(Request $request, User $user): void
    {
        Auth::login($user);

        $request->session()->regenerate();
    }
}
