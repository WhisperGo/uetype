<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;

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
            
            // 1. Cek apakah user sudah terdaftar di database
            $user = User::where('google_id', $googleUser->id)
                        ->orWhere('email', $googleUser->email)
                        ->first();

            if ($user) {
                // JIKA AKUN SUDAH ADA: Langsung sinkronisasi ID dan login (Alur Login Biasa)
                if (!$user->google_id) {
                    $user->update(['google_id' => $googleUser->id]);
                }
                Auth::login($user);
                return redirect()->intended('/typing');
            }

            // JIKA AKUN BELUM ADA (ALUR REGISTER):
            // Jangan simpan ke database dulu. Titipkan data Google ke dalam Session.
            $request->session()->put('google_register_data', [
                'email' => $googleUser->email,
                'google_id' => $googleUser->id,
                'avatar' => $googleUser->avatar,
            ]);

            // Alihkan user ke halaman khusus untuk memilih username
            return redirect()->route('auth.google.choose-username');

        } catch (\Exception $e) {
            return redirect('/login')->with('error', 'Gagal autentikasi via Google.');
        }
    }

    /**
     * Tampilkan halaman form pilih username.
     */
    public function showChooseUsernameForm(Request $request)
    {
        // Pastikan ada session data Google, kalau tidak ada kembalikan ke register
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
        // 1. Validasi data Google di session
        if (!$request->session()->has('google_register_data')) {
            return redirect('/register');
        }

        // 2. Validasi input username dari user (wajib unik, tidak boleh ada spasi)
        $request->validate([
            'username' => [
                'required', 
                'string', 
                'alpha_dash', // Memastikan hanya huruf, angka, dash (-), dan underscore (_)
                'min:3', 
                'max:20', 
                'unique:users,username'
            ],
        ], [
            'username.unique' => 'Nama pengguna ini sudah dipakai, cari nama lain!',
            'username.alpha_dash' => 'Nama pengguna hanya boleh berisi huruf, angka, strip, dan garis bawah.',
        ]);

        $googleData = $request->session()->get('google_register_data');

        // 3. Buat user baru di database secara permanen
        $user = User::create([
            'username' => $request->username,
            'email' => $googleData['email'],
            'google_id' => $googleData['google_id'],
            'avatar' => $googleData['avatar'],
            'password' => encrypt(Str::random(16)), // Password acak aman
        ]);

        // 4. Bersihkan session data Google agar aman
        $request->session()->forget('google_register_data');

        // 5. Otomatis login-kan dan lempar ke game
        Auth::login($user);
        return redirect('/typing');
    }
}