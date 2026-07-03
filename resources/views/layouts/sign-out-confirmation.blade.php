<x-modal name="confirm-sign-out" maxWidth="md" focusable>
    <form method="POST" action="{{ route('logout') }}" class="p-5 sm:p-6">
        @csrf

        <h2 class="font-sans text-xl font-semibold leading-tight text-foreground">
            {{ __('Keluar dari akun?') }}
        </h2>
        <p class="mt-2 text-sm leading-6 text-muted">
            {{ __('Kamu akan mengakhiri sesi saat ini.') }}
        </p>

        <div class="mt-6 flex items-center justify-end gap-2">
            <button type="button"
                x-on:click="$dispatch('close-modal', 'confirm-sign-out')"
                class="rounded-lg px-3 py-1.5 font-sans text-sm text-muted transition-colors duration-150 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                {{ __('Batal') }}
            </button>

            <button type="submit"
                class="rounded-lg px-3 py-1.5 font-sans text-sm font-medium text-danger transition-colors duration-150 hover:bg-danger/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                {{ __('Keluar') }}
            </button>
        </div>
    </form>
</x-modal>
