<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Support\BackLink;
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
    /**
     * Origins a guest must never be sent "back" to: /login is where they already are, and the
     * OAuth pages are mid-flow steps that only make sense forward.
     */
    private const BACK_EXCEPT = ['/login', '/auth/google'];

    /** Login page: offers only "continue with Google". */
    public function showLogin(Request $request): View
    {
        // Reaching the sign-in page means the visitor is not mid-registration. Without this a
        // cancelled username step leaves google_register_data behind, and /auth/google/username
        // keeps rendering for a registration nobody is completing.
        $request->session()->forget('google_register_data');

        // Remembered in the session, because the Referer does not survive the OAuth round trip
        // (it becomes accounts.google.com) nor a cancelled username step (it becomes
        // /auth/google/username, excluded above).
        //
        // Deliberately NOT url.intended: that holds where the guest was HEADED -- the
        // auth-guarded page that bounced them here. Sending them "back" to it would bounce
        // them straight back to this page.
        $backUrl = BackLink::from(
            $request,
            $request->session()->get('auth_origin', route('typing')),
            self::BACK_EXCEPT,
        );

        $request->session()->put('auth_origin', $backUrl);

        return view('auth.login', ['backUrl' => $backUrl]);
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

        // Back from a half-finished registration means abandoning it, and /login is where
        // that lands -- showLogin() is what actually clears the pending Google data.
        return view('auth.google-username', ['backUrl' => route('login')]);
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

            return redirect()->intended('/typing');
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

        // Log them in automatically and send them where they were headed.
        $this->loginAndRegenerate($request, $user);

        // intended(), matching the existing-user path in callback(): a guest bounced off
        // /clans by the auth middleware finished registering IN ORDER to get there, and
        // hardcoding /typing threw that away. session()->regenerate() migrates session data
        // rather than dropping it, so url.intended survives loginAndRegenerate().
        return redirect()->intended('/typing');
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
     * Log in + rotate the session ID.
     *
     * `remember: true` is the product promise -- "signed in until you sign out". Google is
     * the only sign-in path and there is no password column, so an expiring session buys no
     * security at all: it costs the legitimate owner a full OAuth round trip and nothing
     * else. Without it the recaller cookie was never issued (remember_token has existed
     * since the first migration and was never once populated), so closing the browser or
     * idling past SESSION_LIFETIME meant signing in again. Auth::logout() cycles the token
     * AND forgets the cookie, and that is the only way out of the app.
     *
     * regenerate() is kept even though Laravel 12's SessionGuard::updateSession() now
     * regenerates internally: that is a framework implementation detail, while the
     * session-fixation guarantee is this method's own contract (GoogleAuthFlowTest). Without
     * it a session ID held from before login stays valid afterward -- anyone who planted one
     * in the victim's browser (shared machine, lab) is carried in too. Every login path must
     * go through here; never call Auth::login() directly.
     */
    private function loginAndRegenerate(Request $request, User $user): void
    {
        Auth::login($user, remember: true);

        $request->session()->regenerate();
    }
}
