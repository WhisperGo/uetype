<x-app-layout>
    <x-slot name="header">
        <h2 class="font-sans font-semibold text-xl text-typing-text tracking-tight leading-tight">
            {{ __('Dasbor') }}
        </h2>
    </x-slot>

    <div class="py-12">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8">
            <div class="bg-typing-surface/60 border border-white/5 overflow-hidden shadow-glow sm:rounded-2xl">
                <div class="p-6 text-typing-text">
                    {{ __('Kamu sudah masuk!') }}
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
