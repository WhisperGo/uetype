<x-guest-layout>
    <form method="POST" action="{{ route('register') }}">
        @csrf

        <!-- ===== GRID CONTAINER 2x2 ===== -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            
            <!-- 1. Username (Baris 1, Kolom 1) -->
            <div>
                <x-input-label for="username" :value="__('Nama pengguna')" />
                <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" required autofocus autocomplete="username" />
                <x-input-error :messages="$errors->get('username')" class="mt-2" />
            </div>

            <!-- 2. Email Address (Baris 1, Kolom 2) -->
            <div>
                <x-input-label for="email" :value="__('Email')" />
                <x-text-input id="email" class="block mt-1 w-full" type="email" name="email" :value="old('email')" required autocomplete="email" />
                <x-input-error :messages="$errors->get('email')" class="mt-2" />
            </div>

            <!-- 3. Password (Baris 2, Kolom 1) -->
            <div>
                <x-input-label for="password" :value="__('Kata sandi')" />
                <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <!-- 4. Confirm Password (Baris 2, Kolom 2) -->
            <div>
                <x-input-label for="password_confirmation" :value="__('Konfirmasi kata sandi')" />
                <x-text-input id="password_confirmation" class="block mt-1 w-full" type="password" name="password_confirmation" required autocomplete="new-password" />
                <x-input-error :messages="$errors->get('password_confirmation')" class="mt-2" />
            </div>
            
        </div>

        <!-- Tombol Google (Tetap di Luar Grid agar Melebar Penuh di Bawahnya) -->
        <div class="mt-5">
            <a href="{{ route('auth.google') }}"
                class="w-full flex items-center justify-center gap-3 px-4 py-3 bg-surface border border-white/5 rounded-2xl font-sans text-sm font-semibold text-foreground hover:bg-white/5 transition duration-200 shadow-lg">
                <!-- SVG Icon Google -->
                <svg xmlns="http://www.w3.org/2000/svg" class="w-5 h-5" viewBox="0 0 24 24">
                    <g transform="matrix(1, 0, 0, 1, 0, 0)">
                        <!-- Bagian Biru -->
                        <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.53-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-8.17z" />
                        <!-- Bagian Hijau -->
                        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.11 0-5.74-2.11-6.68-4.96H1.32v3.15C3.31 20.36 7.38 24 12 24z" />
                        <!-- Bagian Kuning -->
                        <path fill="#FBBC05" d="M5.32 14.24A7.16 7.16 0 0 1 5 12c0-.79.13-1.57.32-2.34V6.51H1.32A11.94 11.94 0 0 0 0 12c0 1.92.45 3.74 1.32 5.39l4-3.15z" />
                        <!-- Bagian Merah -->
                        <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.38 0 3.31 3.64 1.32 7.51l4 3.15c.94-2.85 3.57-4.91 6.68-4.91z" />
                    </g>
                </svg>
                Daftar dengan Google
            </a>
        </div>

        <!-- Bagian Aksi Tombol Submit -->
        <div class="flex items-center justify-end mt-5">
            <a class="underline text-sm text-muted hover:text-foreground rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-surface focus:ring-brand"
                href="{{ route('login') }}">
                {{ __('Sudah punya akun?') }}
            </a>

            <x-primary-button class="ms-4">
                {{ __('Daftar') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>