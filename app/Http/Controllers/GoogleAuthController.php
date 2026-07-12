<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

/**
 * Handles Google OAuth sign-in: redirects to Google and processes the callback,
 * creating or matching a user by their Google identity.
 */
class GoogleAuthController extends Controller
{
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
                if (!$user->google_id) {
                    $user->update(
                        [
                            'google_id' => $googleUser->id,
                            'avatar' => $googleUser->avatar
                        ]
                    );
                }
                Auth::login($user);
                return redirect()->intended('/typing');
            }

            // Akun belum ada: jangan simpan ke database dulu, titipkan data Google ke session.
            $request->session()->put('google_register_data', [
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]);

            $request->session()->put('google_register_data', [
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar, 
            ]);
            
            return redirect()->route('auth.google.choose-username');

        } catch (\Exception $e) {
            return redirect('/login')->with('error', __('auth.google_failed'));
        }
    }

    /**
     * Tampilkan halaman form pilih username.
     */
    public function showChooseUsernameForm(Request $request)
    {
        // Perlu session data Google, kalau tidak ada kembalikan ke register.
        if (!$request->session()->has('google_register_data')) {
            return redirect('/register');
        }

        return view('auth.google-username');
    }

    /**
     * Simpan user baru setelah memilih username.
     */
    public function storeUsername(Request $request)
    {
        // Validasi data Google di session.
        if (!$request->session()->has('google_register_data')) {
            return redirect('/register');
        }

        $googleData = $request->session()->get('google_register_data');

        // Cek ulang database sebelum insert, untuk mencegah duplicate entry.
        $existingUser = User::where('google_id', $googleData['google_id'])
                            ->orWhere('email', $googleData['email'])
                            ->first();

        if ($existingUser) {
            // Datanya sudah ada: batalkan register, langsung login.
            $request->session()->forget('google_register_data');
            Auth::login($existingUser);
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
                'unique:users,username'
            ],
        ], [
            'username.unique' => __('auth.username.taken'),
            'username.alpha_dash' => __('auth.username.format'),
        ]);

        // Buat user baru di database.
        $user = User::create([
            'username' => $request->username,
            'email' => $googleData['email'],
            'google_id' => $googleData['google_id'],
            'avatar' => $googleData['avatar'],
            'password' => encrypt(\Illuminate\Support\Str::random(16)), 
        ]);

        // Bersihkan session data Google.
        $request->session()->forget('google_register_data');

        // Otomatis login-kan dan lempar ke game.
        Auth::login($user);
        return redirect('/typing');
    }
}