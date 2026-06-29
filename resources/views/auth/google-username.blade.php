<x-guest-layout>
    <div class="mb-4 text-sm text-typing-muted">
        {{ __('Satu langkah lagi! Silakan tentukan nama pengguna (username) unik kamu untuk profil game UeType.') }}
    </div>

    <form method="POST" action="{{ route('auth.google.store-username') }}">
        @csrf

        <!-- Input Username -->
        <div>
            <x-input-label for="username" :value="__('Nama Pengguna Baru')" />
            <x-text-input id="username" class="block mt-1 w-full font-mono" type="text" name="username" :value="old('username')" required autofocus autocomplete="off" placeholder="contoh: ksatria_ketik" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <div class="flex items-center justify-end mt-6">
            <!-- Tombol Batal -->
            <a class="underline text-sm text-typing-muted hover:text-typing-text rounded-md focus:outline-none"
                href="{{ route('login') }}">
                {{ __('Batal') }}
            </a>

            <!-- Tombol Konfirmasi Menyimpan Akun -->
            <x-primary-button class="ms-4">
                {{ __('Selesaikan Pendaftaran') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>