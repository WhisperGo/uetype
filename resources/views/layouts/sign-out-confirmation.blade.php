{{-- Confirmation modal for signing out; posts to the logout route. --}}
<x-modal name="confirm-sign-out" maxWidth="md" focusable>
    <form method="POST" action="{{ route('logout') }}" class="p-5 sm:p-6">
        @csrf

        <h2 class="font-mono text-xl font-semibold leading-tight text-foreground">
            {{ __('account.sign_out.title') }}
        </h2>
        <p class="mt-2 text-sm leading-6 text-muted">
            {{ __('account.sign_out.body') }}
        </p>

        <div class="mt-6 flex items-center justify-end gap-2">
            <button type="button"
                x-on:click="$dispatch('close-modal', 'confirm-sign-out')"
                class="rounded-lg px-3 py-1.5 font-mono text-sm text-muted transition-colors duration-150 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                {{ __('account.sign_out.cancel') }}
            </button>

            <button type="submit"
                class="rounded-lg px-3 py-1.5 font-mono text-sm font-medium text-danger transition-colors duration-150 hover:bg-danger/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                {{ __('account.sign_out.confirm') }}
            </button>
        </div>
    </form>
</x-modal>
