<x-guest-layout>
    <x-auth-session-status class="mb-4" :status="session('status')" />

    @include('auth._google-panel', ['mode' => 'login'])

    @if (false)
        <form method="POST" action="{{ route('login') }}">
            @csrf

            <div>
                <x-input-label for="username" :value="__('Nama pengguna')" />
                <x-text-input id="username" class="block mt-1 w-full" type="text" name="username" :value="old('username')" required
                    autofocus autocomplete="username" />
                <x-input-error :messages="$errors->get('username')" class="mt-2" />
            </div>

            <div class="mt-4">
                <x-input-label for="password" :value="__('Kata sandi')" />
                <x-text-input id="password" class="block mt-1 w-full" type="password" name="password" required
                    autocomplete="current-password" />
                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="block mt-4">
                <label for="remember_me" class="inline-flex items-center">
                    <input id="remember_me" type="checkbox"
                        class="rounded bg-background/60 border-white/10 text-brand shadow-sm focus:ring-brand focus:ring-offset-surface"
                        name="remember">
                    <span class="ms-2 text-sm text-muted">{{ __('Ingat saya') }}</span>
                </label>
            </div>

            <div class="flex flex-col sm:flex-row sm:items-center justify-between gap-4 mt-6">
                <div>
                    @if (Route::has('register'))
                        <a class="underline text-xs text-muted hover:text-foreground rounded-md focus:outline-none"
                            href="{{ route('register') }}">
                            {{ __('Belum punya akun?') }}
                        </a>
                    @endif
                </div>

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
    @endif
</x-guest-layout>
