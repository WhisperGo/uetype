<x-app-layout>
    <x-slot name="header">
        <h2 class="font-semibold text-xl text-typing-text leading-tight">
            {{ __('Profile') }}
        </h2>
    </x-slot>

    <div class="py-12" x-data="{ activeTab: 'stats' }">
        <div class="max-w-7xl mx-auto sm:px-6 lg:px-8 space-y-6">
            
            <!-- Tabs Navigation -->
            <div class="border-b border-gray-800 dark:border-gray-700 mb-6">
                <nav class="-mb-px flex space-x-8" aria-label="Tabs">
                    <button @click="activeTab = 'stats'"
                            :class="activeTab === 'stats' ? 'border-indigo-500 text-typing-accent dark:text-indigo-400 font-bold' : 'border-transparent text-typing-muted hover:text-typing-text hover:border-gray-300 font-medium'"
                            class="whitespace-nowrap py-4 px-1 border-b-2 text-sm transition-colors duration-200">
                        User Stats
                    </button>
                    
                    <button @click="activeTab = 'settings'"
                            :class="activeTab === 'settings' ? 'border-indigo-500 text-typing-accent dark:text-indigo-400 font-bold' : 'border-transparent text-typing-muted hover:text-typing-text hover:border-gray-300 font-medium'"
                            class="whitespace-nowrap py-4 px-1 border-b-2 text-sm transition-colors duration-200">
                        Account Settings
                    </button>
                </nav>
            </div>

            <!-- Stats Tab Content -->
            <div x-show="activeTab === 'stats'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" class="space-y-6">
                <div class="p-4 sm:p-8 bg-typing-surface shadow sm:rounded-lg flex justify-between items-center">
                    <div class="text-center">
                        <p class="text-sm text-typing-muted">Highest WPM</p>
                        <p class="text-3xl font-bold text-typing-accent">{{ $user->highest_wpm ?? 0 }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm text-typing-muted">XP</p>
                        <p class="text-3xl font-bold text-green-600">{{ $user->xp ?? 0 }}</p>
                    </div>
                    <div class="text-center">
                        <p class="text-sm text-typing-muted">Coins</p>
                        <p class="text-3xl font-bold text-yellow-500">{{ $user->coins ?? 0 }}</p>
                    </div>
                </div>

                <div class="p-4 sm:p-8 bg-typing-surface shadow sm:rounded-lg">
                    <div class="w-full">
                        <h3 class="text-lg font-medium text-typing-text mb-4">Riwayat Pertandingan Terakhir</h3>
                        @if(isset($recentMatches) && $recentMatches->count() > 0)
                            <table class="w-full text-left border-collapse">
                                <thead>
                                    <tr>
                                        <th class="border-b py-2 text-sm font-semibold text-typing-text">Tanggal</th>
                                        <th class="border-b py-2 text-sm font-semibold text-typing-text">Mode</th>
                                        <th class="border-b py-2 text-sm font-semibold text-typing-text">WPM</th>
                                        <th class="border-b py-2 text-sm font-semibold text-typing-text">Akurasi</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach($recentMatches as $participant)
                                    <tr>
                                        <td class="border-b py-2 text-sm text-typing-text">{{ $participant->created_at ? $participant->created_at->format('d M Y H:i') : '-' }}</td>
                                        <td class="border-b py-2 text-sm text-typing-text capitalize">{{ str_replace('_', ' ', $participant->match?->mode_played ?? 'Practice') }}</td>
                                        <td class="border-b py-2 text-sm text-typing-text font-bold">{{ $participant->wpm }}</td>
                                        <td class="border-b py-2 text-sm text-typing-text">{{ $participant->accuracy }}%</td>
                                    </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        @else
                            <p class="text-typing-muted text-sm">Belum ada riwayat mengetik.</p>
                        @endif
                    </div>
                </div>
            </div>

            <!-- Settings Tab Content -->
            <div x-show="activeTab === 'settings'" x-transition:enter="transition ease-out duration-300" x-transition:enter-start="opacity-0 translate-y-2" x-transition:enter-end="opacity-100 translate-y-0" style="display: none;" class="space-y-6">
                <div class="p-4 sm:p-8 bg-typing-surface shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        @include('profile.partials.update-profile-information-form')
                    </div>
                </div>

                <div class="p-4 sm:p-8 bg-typing-surface shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        @include('profile.partials.update-password-form')
                    </div>
                </div>

                <div class="p-4 sm:p-8 bg-typing-surface shadow sm:rounded-lg">
                    <div class="max-w-xl">
                        @include('profile.partials.delete-user-form')
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-app-layout>
