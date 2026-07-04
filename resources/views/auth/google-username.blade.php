<x-guest-layout>
    <div class="flex flex-col items-center text-center">
        <img src="{{ asset('icon/uetype_mascot.png') }}" alt="Maskot UeType"
            class="h-20 w-20 object-contain drop-shadow-[0_0_18px_rgba(var(--color-brand)/0.35)]">

        <p class="mt-4 font-mono text-[0.7rem] uppercase tracking-[0.35em] text-gold">
            {{ __('auth.username.step') }}
        </p>

        <h1 class="mt-2 font-display text-h6 leading-tight text-foreground">
            {{ __('auth.username.title_start') }} <span class="text-brand">{{ __('auth.username.title_accent') }}</span>
        </h1>

        <p class="mt-3 max-w-xs font-mono text-sm leading-6 text-muted">
            {{ __('auth.username.subtitle') }}
        </p>
    </div>

    <form method="POST" action="{{ route('auth.google.store-username') }}" class="mt-8">
        @csrf

        <div>
            <x-input-label for="username" :value="__('auth.username.label')" />
            <x-text-input id="username" class="block mt-1 w-full font-mono" type="text" name="username"
                :value="old('username')" required autofocus autocomplete="off" :placeholder="__('auth.username.placeholder')" />
            <x-input-error :messages="$errors->get('username')" class="mt-2" />
        </div>

        <div class="mt-6 flex items-center justify-end gap-4">
            <a class="text-sm text-muted underline underline-offset-2 transition-colors hover:text-foreground rounded-md focus:outline-none"
                href="{{ route('login') }}">
                {{ __('auth.username.cancel') }}
            </a>

            <x-primary-button>
                {{ __('auth.username.finish') }}
            </x-primary-button>
        </div>
    </form>
</x-guest-layout>
