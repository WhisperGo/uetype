<x-guest-layout>
    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form method="POST" action="{{ route('login') }}">
        @csrf

        <!-- Username -->
        <div>
            <x-input-label for="username" :value="__('Nama pengguna')" />
            <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" required
                autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="mt-4">
            <x-input-label for="password" :value="__('Kata sandi')" />

            <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required
                autocomplete="current-password" />

            <x-input-error :messages="$errors->get('password')" class="mt-2" />
        </div>

        <!-- Remember Me -->
        <div class="block mt-4">
            <label for="remember_me" class="inline-flex items-center">
                <input id="remember_me" type="checkbox"
                    class="rounded bg-background/60 border-white/10 text-brand shadow-sm focus:ring-brand focus:ring-offset-surface"
                    name="remember">
                <span class="ms-2 text-sm text-muted">{{ __('Ingat saya') }}</span>
            </label>
        </div>

        <!-- Tombol Google -->
        <div class="mt-4">
            <a href="{{ route('auth.google') }}"
                class="w-full flex items-center justify-center gap-3 px-4 py-3 bg-surface border border-white/5 rounded-2xl font-sans text-sm font-semibold text-foreground hover:bg-white/5 transition duration-200 shadow-lg">
                <!-- SVG Icon Google -->
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24">
                    <g transform="matrix(1, 0, 0, 1, 0, 0)">
                        <!-- Bagian Biru (Kanan Atas & Tengah) -->
                        <path fill="#4285F4"
                            d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.53-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-8.17z" />
                        <!-- Bagian Hijau (Bawah) -->
                        <path fill="#34A853"
                            d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.11 0-5.74-2.11-6.68-4.96H1.32v3.15C3.31 20.36 7.38 24 12 24z" />
                        <!-- Bagian Kuning (Kiri Tengah) -->
                        <path fill="#FBBC05"
                            d="M5.32 14.24A7.16 7.16 0 0 1 5 12c0-.79.13-1.57.32-2.34V6.51H1.32A11.94 11.94 0 0 0 0 12c0 1.92.45 3.74 1.32 5.39l4-3.15z" />
                        <!-- Bagian Merah (Atas) -->
                        <path fill="#EA4335"
                            d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.38 0 3.31 3.64 1.32 7.51l4 3.15c.94-2.85 3.57-4.91 6.68-4.91z" />
                    </g>
                </svg>
                Masuk dengan Google
            </a>
        </div>

        <!-- Bagian Aksi Tombol (Ditambahkan tautan Registrasi) -->
        <!-- Bagian Aksi Tombol (Kiri: Register, Kanan: Lupa Password + Masuk) -->
        <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-6">

            <!-- Kiri: Belum punya akun -->
            <div>
                @if (Route::has('register'))
                    <a class="underline text-xs text-muted hover:text-foreground rounded-md focus:outline-none"
                        href="{{ route('register') }}">
                        {{ __('Belum punya akun?') }}
                    </a>
                @endif
            </div>

            <!-- Kanan: Rombongan Lupa Kata Sandi & Tombol Masuk -->
            <div class="flex items-center justify-between sm:justify-end gap-4">
                @if (Route::has('password.request'))
                    <a class="underline text-xs text-muted hover:text-foreground rounded-md focus:outline-none"
                        href="{{ route('password.request') }}">
                        {{ __('Lupa kata sandi?') }}
                    </a>
                @endif

                <x-primary-button>
                    {{ __('Masuk') }}
                </x-primary-button>
            </div>

        </div>
    </form>
</x-guest-layout>
