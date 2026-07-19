<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;
use Laravel\Socialite\Facades\Socialite;

/**
 * Satu-satunya jalur autentikasi aplikasi: Google OAuth. Tabel `users` memang tak
 * punya kolom `password` -- scaffolding password bawaan Breeze sudah dihapus
 * seluruhnya, termasuk halaman login/logout-nya yang kini tinggal di sini.
 */
class GoogleAuthController extends Controller
{
    /** Halaman masuk: hanya menawarkan "lanjut dengan Google". */
    public function showLogin(): View
    {
        return view('auth.login');
    }

    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    public function callback(Request $request)
    {
        try {
            $googleUser = Socialite::driver('google')->user();

            // Cek apakah user sudah terdaftar di database.
            $user = User::where('google_id', $googleUser->id)
                ->orWhere('email', $googleUser->email)
                ->first();

            if ($user) {
                // Akun sudah ada: sinkronisasi ID lalu login.
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

            // Akun belum ada: jangan simpan ke database dulu, titipkan data Google ke session.
            $request->session()->put('google_register_data', [
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]);

            return redirect()->route('auth.google.choose-username');

        } catch (\Exception $e) {
            // Tanpa log, misconfig OAuth (client ID salah, redirect URI tak cocok)
            // tak bisa dibedakan dari user yang sekadar membatalkan izin.
            Log::warning('Google OAuth gagal', ['exception' => $e]);

            return redirect()->route('login')->with('error', __('auth.google_failed'));
        }
    }

    /**
     * Tampilkan halaman form pilih username.
     */
    public function showChooseUsernameForm(Request $request)
    {
        // Perlu session data Google, kalau tidak ada kembalikan ke halaman masuk.
        if (! $request->session()->has('google_register_data')) {
            return redirect()->route('login');
        }

        return view('auth.google-username');
    }

    /**
     * Simpan user baru setelah memilih username.
     */
    public function storeUsername(Request $request)
    {
        // Validasi data Google di session.
        if (! $request->session()->has('google_register_data')) {
            return redirect()->route('login');
        }

        $googleData = $request->session()->get('google_register_data');

        // Cek ulang database sebelum insert, untuk mencegah duplicate entry.
        $existingUser = User::where('google_id', $googleData['google_id'])
            ->orWhere('email', $googleData['email'])
            ->first();

        if ($existingUser) {
            // Datanya sudah ada: batalkan register, langsung login.
            $request->session()->forget('google_register_data');
            $this->loginAndRegenerate($request, $existingUser);

            return redirect('/typing');
        }

        // Validasi input username dari user (wajib unik).
        $request->validate([
            'username' => [
                'required',
                'string',
                'alpha_dash',
                'min:3',
                'max:20',
                'unique:users,username',
            ],
        ], [
            'username.unique' => __('auth.username.taken'),
            'username.alpha_dash' => __('auth.username.format'),
        ]);

        // Buat user baru di database. Tak ada kolom password -- identitas
        // sepenuhnya bersandar pada google_id.
        $user = User::create([
            'username' => $request->username,
            'email' => $googleData['email'],
            'google_id' => $googleData['google_id'],
            'avatar' => $googleData['avatar'],
        ]);

        // Bersihkan session data Google.
        $request->session()->forget('google_register_data');

        // Otomatis login-kan dan lempar ke game.
        $this->loginAndRegenerate($request, $user);

        return redirect('/typing');
    }

    public function logout(Request $request): RedirectResponse
    {
        // Tandai offline sebelum logout, supaya teman langsung melihat status offline.
        Auth::user()?->markOffline();

        Auth::guard('web')->logout();

        $request->session()->invalidate();

        $request->session()->regenerateToken();

        return redirect('/');
    }

    /**
     * Login + ganti session ID. Auth::login() TIDAK mengganti session ID sendiri,
     * jadi tanpa regenerate() session yang dipegang sejak sebelum login tetap
     * valid sesudahnya -- siapa pun yang sempat menanamkan session ID ke browser
     * korban (mesin bersama, lab) ikut terbawa masuk. Semua jalur login harus
     * lewat sini; jangan panggil Auth::login() langsung.
     */
    private function loginAndRegenerate(Request $request, User $user): void
    {
        Auth::login($user);

        $request->session()->regenerate();
    }
}
