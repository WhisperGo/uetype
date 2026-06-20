<x-guest-layout>
    <div class="mb-4 text-sm text-typing-muted">
        {{ __('Terima kasih sudah mendaftar! Sebelum mulai, verifikasi dulu alamat emailmu dengan mengeklik tautan yang baru saja kami kirim. Jika belum menerima emailnya, kami akan dengan senang hati mengirim ulang.') }}
    </div>

    @if (session('status') == 'verification-link-sent')
        <div class="mb-4 font-medium text-sm text-typing-success">
            {{ __('Tautan verifikasi baru telah dikirim ke alamat email yang kamu berikan saat mendaftar.') }}
        </div>
    @endif

    <div class="mt-4 flex items-center justify-between">
        <form method="POST" action="{{ route('verification.send') }}">
            @csrf

            <div>
                <x-primary-button>
                    {{ __('Kirim Ulang Email Verifikasi') }}
                </x-primary-button>
            </div>
        </form>

        <form method="POST" action="{{ route('logout') }}">
            @csrf

            <button type="submit" class="underline text-sm text-typing-muted hover:text-typing-text rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-offset-typing-surface focus:ring-typing-accent">
                {{ __('Keluar') }}
            </button>
        </form>
    </div>
</x-guest-layout>
