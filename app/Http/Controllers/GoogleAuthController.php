<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

class GoogleAuthController extends Controller
{
    /**
     * Alihkan user ke halaman login Google.
     */
    public function redirect()
    {
        return Socialite::driver('google')->redirect();
    }

    /**
     * Tangani callback dari Google setelah user berhasil login.
     */
    public function callback()
    {
        try {
            // Ambil data user dari Google
            $googleUser = Socialite::driver('google')->user();
            
            // Cari apakah user dengan google_id atau email ini sudah terdaftar
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if ($user) {
                // Jika user sudah ada tetapi google_id belum tersimpan, update id-nya
                if (!$user->google_id) {
                    $user->update(['google_id' => $googleUser->id]);
                }
            } else {
                // Jika benar-benar user baru, buat akun otomatis
                // Buat username acak dari nama Google atau email
                $username = Str::slug($googleUser->name ?? explode('@', $googleUser->email)[0], '_') . rand(10, 99);

                $user = User::create([
                    'username' => $username,
                    'email' => $googleUser->email,
                    'google_id' => $googleUser->id,
                    'avatar' => $googleUser->avatar, // Jika tabel user menampung link foto profil
                    'password' => encrypt(Str::random(16)), // Password acak aman karena login via OAuth
                ]);
            }

            // Login-kan user ke aplikasi
            Auth::login($user);

            // Alihkan ke halaman game/typing utama
            return redirect()->intended('/typing');

        } catch (\Exception $e) {
            // Jika terjadi error (misal token expired atau dibatalkan user)
            return redirect('/login')->with('error', 'Gagal login menggunakan Google. Silakan coba lagi.');
        }
    }
}