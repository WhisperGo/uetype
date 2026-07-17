<x-app-layout>
    <x-slot name="header">
        <h2 class="font-mono font-semibold text-xl text-foreground tracking-tight leading-tight">
            {{ __('dashboard.title') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-surface/60 border border-white/5 overflow-hidden shadow-lg sm:rounded-2xl">
                <div class="p-6 text-foreground">
                    {{ __('dashboard.signed_in') }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
