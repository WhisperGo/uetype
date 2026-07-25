{{-- Settings page: language, account (avatar, display name, sign out), and the
     danger zone (account deletion, gated behind a username-match confirmation). --}}
<div class="py-10">
    <div class="max-w-4xl px-4 mx-auto space-y-10 sm:px-6 lg:px-8">

        <div>
            <h1 class="font-display text-fluid-title tracking-wide text-foreground">{{ __('settings.title') }}</h1>
            <p class="mt-3 font-mono text-sm text-muted">{{ __('settings.subtitle') }}</p>
        </div>

        <section class="space-y-3">
            <p class="text-xs uppercase tracking-[0.2em] text-muted font-mono">{{ __('settings.language.label') }}</p>
            <div class="p-6 border bg-surface/60 border-white/5 rounded-2xl">
                <p class="font-mono text-sm text-muted mb-4">{{ __('settings.language.description') }}</p>
                <div class="grid gap-3 sm:grid-cols-2">
                    @foreach (App\Support\Locale::labels() as $code => $label)
                        @php($isActive = app()->getLocale() === $code)
                        <button type="button" wire:click="setLocale('{{ $code }}')"
                            class="flex items-center justify-between px-4 py-3 font-mono text-sm text-left transition border rounded-xl {{ $isActive ? 'border-brand bg-brand/10 text-foreground' : 'border-white/5 bg-surface/40 text-muted hover:text-foreground hover:border-white/10' }}">
                            <span class="font-semibold">{{ $label }}</span>
                            @if ($isActive)
                                <svg class="w-4 h-4 text-brand-bright" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                </svg>
                            @endif
                        </button>
                    @endforeach
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <p class="text-xs uppercase tracking-[0.2em] text-muted font-mono">{{ __('settings.account.label') }}</p>
            <div class="p-6 border bg-surface/60 border-white/5 rounded-2xl sm:p-8 space-y-6">

                <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
                    <div class="flex items-center gap-4">
                        @if (auth()->user()->avatar)
                            <img src="{{ auth()->user()->avatar }}" alt="{{ auth()->user()->username }}"
                                class="object-cover border w-14 h-14 rounded-2xl border-white/10 shrink-0"
                                referrerpolicy="no-referrer">
                        @else
                            <span class="flex items-center justify-center text-xl font-bold uppercase w-14 h-14 rounded-2xl bg-gradient-to-br from-brand to-gold text-background shrink-0">
                                {{ Str::substr(auth()->user()->username, 0, 1) }}
                            </span>
                        @endif
                        <div>
                            <p class="font-mono text-lg font-bold text-foreground">{{ auth()->user()->username }}</p>
                            @if (auth()->user()->google_id)
                                <span class="inline-flex items-center gap-1.5 mt-1 px-2 py-0.5 rounded-md bg-white/5 text-muted text-xs font-mono">
                                    <svg class="w-3 h-3 shrink-0" viewBox="0 0 24 24" aria-hidden="true">
                                        <path fill="#4285F4" d="M23.745 12.27c0-.7-.06-1.4-.19-2.07H12v4.51h6.6c-.29 1.53-1.14 2.82-2.4 3.68v3.05h3.88c2.27-2.09 3.66-5.17 3.66-8.17z" />
                                        <path fill="#34A853" d="M12 24c3.24 0 5.95-1.08 7.93-2.91l-3.88-3.05c-1.08.72-2.45 1.16-4.05 1.16-3.11 0-5.74-2.11-6.68-4.96H1.32v3.15C3.31 20.36 7.38 24 12 24z" />
                                        <path fill="#FBBC05" d="M5.32 14.24A7.16 7.16 0 0 1 5 12c0-.79.13-1.57.32-2.34V6.51H1.32A11.94 11.94 0 0 0 0 12c0 1.92.45 3.74 1.32 5.39l4-3.15z" />
                                        <path fill="#EA4335" d="M12 4.75c1.77 0 3.35.61 4.6 1.8l3.42-3.42C17.95 1.19 15.24 0 12 0 7.38 0 3.31 3.64 1.32 7.51l4 3.15c.94-2.85 3.57-4.91 6.68-4.91z" />
                                    </svg>
                                    {{ __('settings.account.connected_google') }}
                                </span>
                            @endif
                        </div>
                    </div>
                    <p class="font-mono text-sm text-muted">{{ auth()->user()->email }}</p>
                </div>

                <div class="pt-6 border-t border-white/5" x-data="{ saved: false }"
                    x-on:username-saved.window="saved = true; setTimeout(() => saved = false, 2000)">
                    <label for="settings-username" class="block font-mono text-sm font-semibold text-foreground">{{ __('settings.account.display_name') }}</label>
                    <p class="mt-1 font-mono text-xs text-muted">{{ __('settings.account.display_name_hint') }}</p>
                    <div class="flex flex-col gap-3 mt-3 sm:flex-row sm:items-start">
                        <div class="flex-1">
                            <input id="settings-username" type="text" wire:model="username"
                                class="w-full px-4 py-2.5 font-mono text-sm rounded-xl bg-background border border-white/10 text-foreground focus:border-brand focus:ring-1 focus:ring-brand focus:outline-none">
                            @error('username')
                                <p class="mt-2 font-mono text-xs text-danger">{{ $message }}</p>
                            @enderror
                        </div>
                        <button type="button" wire:click="saveUsername"
                            class="px-5 py-2.5 font-mono text-sm font-semibold rounded-xl bg-brand text-foreground transition hover:bg-brand/80 focus:outline-none focus-visible:ring-1 focus-visible:ring-brand-bright">
                            {{ __('settings.account.save') }}
                        </button>
                        <span x-show="saved" x-cloak x-transition class="self-center font-mono text-xs text-brand-bright">{{ __('settings.account.saved') }}</span>
                    </div>
                </div>

                <div class="pt-6 border-t border-white/5">
                    <button type="button" x-on:click="$dispatch('open-modal', 'confirm-sign-out')"
                        class="inline-flex items-center gap-2 px-4 py-2 font-mono text-sm font-medium transition border rounded-xl border-white/10 text-muted hover:text-foreground hover:border-white/20">
                        <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                        </svg>
                        {{ __('settings.account.log_out') }}
                    </button>
                </div>
            </div>
        </section>

        <section class="space-y-3">
            <p class="text-xs uppercase tracking-[0.2em] text-muted font-mono">{{ __('settings.danger.label') }}</p>
            <div class="flex flex-col gap-4 p-6 border sm:flex-row sm:items-center sm:justify-between bg-danger/5 border-danger/20 rounded-2xl">
                <div>
                    <p class="font-mono text-sm font-semibold text-danger">{{ __('settings.danger.delete') }}</p>
                    <p class="mt-1 font-mono text-xs text-muted">{{ __('settings.danger.delete_description') }}</p>
                </div>
                <button type="button" x-on:click="$dispatch('open-modal', 'confirm-account-deletion')"
                    class="px-4 py-2 font-mono text-sm font-semibold transition border rounded-xl border-danger/40 text-danger hover:bg-danger/10 focus:outline-none focus-visible:ring-1 focus-visible:ring-danger">
                    {{ __('settings.danger.delete') }}
                </button>
            </div>
        </section>

        <x-modal name="confirm-account-deletion" focusable>
            <div class="p-6 sm:p-8" x-data="{ typed: '' }"
                x-on:close-modal.window="$event.detail === 'confirm-account-deletion' ? typed = '' : null">
                <h2 class="font-display text-xl text-foreground">{{ __('settings.danger.confirm_title') }}</h2>
                <p class="mt-3 font-mono text-sm text-muted">{{ __('settings.danger.confirm_body') }}</p>

                <input type="text" wire:model="confirmUsername" x-model="typed"
                    placeholder="{{ __('settings.danger.confirm_placeholder') }}"
                    class="w-full px-4 py-2.5 mt-5 font-mono text-sm rounded-xl bg-background border border-white/10 text-foreground focus:border-danger focus:ring-1 focus:ring-danger focus:outline-none">
                @error('confirmUsername')
                    <p class="mt-2 font-mono text-xs text-danger">{{ $message }}</p>
                @enderror

                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" x-on:click="$dispatch('close-modal', 'confirm-account-deletion')"
                        class="px-4 py-2 font-mono text-sm font-medium transition border rounded-xl border-white/10 text-muted hover:text-foreground">
                        {{ __('settings.danger.confirm_cancel') }}
                    </button>
                    <button type="button" wire:click="deleteAccount"
                        x-bind:disabled="typed !== @js(auth()->user()->username)"
                        class="px-4 py-2 font-mono text-sm font-semibold text-white transition rounded-xl bg-danger hover:bg-danger/80 disabled:opacity-40 disabled:cursor-not-allowed focus:outline-none">
                        {{ __('settings.danger.confirm_delete') }}
                    </button>
                </div>
            </div>
        </x-modal>

    </div>
</div>
