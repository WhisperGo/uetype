<x-app-layout>
    <x-slot name="header">
        <h2 class="font-mono text-xl font-semibold tracking-tight text-foreground">
            {{ __('profile.header') }}
        </h2>
    </x-slot>

    <div class="py-10" x-data="{ activeTab: 'BestRecords' }">
        <div class="max-w-5xl px-4 mx-auto space-y-8 sm:px-6 lg:px-8">

            <!-- ===== IDENTITY HEADER ===== -->
            <div class="relative p-6 overflow-hidden border bg-surface/70 border-white/10 rounded-3xl sm:p-8 shadow-glow">
                <div class="absolute w-48 h-48 rounded-full -top-16 -right-16 bg-brand/10 blur-3xl"></div>
                <div class="relative flex flex-col gap-6 sm:flex-row sm:items-center">
                    
                    <!-- MODIFIKASI DISINI: RENDER AVATAR GOOGLE / INITIALS FALLBACK -->
                    @if($user->avatar)
                        <!-- Tampilkan Foto Profil Asli Google -->
                        <img src="{{ $user->avatar }}" 
                             alt="{{ $user->username }}" 
                             class="w-20 h-20 object-cover rounded-2xl border border-white/10 shadow-md shrink-0"
                             referrerpolicy="no-referrer">
                    @else
                        <!-- Cadangan Inisial Huruf (Jika daftar manual) -->
                        <div class="flex items-center justify-center w-20 h-20 text-3xl font-bold uppercase rounded-2xl bg-gradient-to-br from-brand to-gold text-background shrink-0">
                            {{ \Illuminate\Support\Str::substr($user->username, 0, 1) }}
                        </div>
                    @endif

                    <div class="flex-1">
                        <div class="flex flex-wrap items-center gap-3">
                            <h1 class="font-mono text-2xl font-bold text-foreground">{{ $user->username }}</h1>
                            @if($user->clan)
                                <span class="px-2 py-0.5 rounded-md bg-brand/15 text-brand-bright text-xs font-mono font-semibold">
                                    [{{ $user->clan->tag }}] {{ ucfirst($user->clan_role ?? __('profile.clan_role_member')) }}
                                </span>
                            @endif
                            <span class="px-2 py-0.5 rounded-md bg-white/5 text-muted text-xs font-mono">{{ __('profile.level', ['level' => $stats['level']]) }}</span>
                        </div>
                        <p class="mt-1 font-mono text-sm text-muted">{{ $user->email }}</p>
                        <p class="mt-1 text-xs text-muted">
                            {{ __('profile.joined', ['date' => \App\Support\AppTime::format($user->joined_at ?? $user->created_at, 'd F Y')]) }}
                        </p>
                        <div class="max-w-xs mt-3">
                            <div class="flex justify-between text-[0.65rem] text-muted font-mono mb-1">
                                <span>{{ $stats['level_progress'] }} / {{ $stats['level_needed'] }} XP</span>
                                <span>{{ __('profile.level', ['level' => $stats['level'] + 1]) }}</span>
                            </div>
                            <div class="h-2 overflow-hidden rounded-full bg-white/5">
                                <div class="h-full rounded-full bg-gradient-to-r from-brand to-gold" style="width: {{ $stats['level_needed'] > 0 ? ($stats['level_progress'] / $stats['level_needed']) * 100 : 0 }}%"></div>
                            </div>
                        </div>
                    </div>
                    <div class="pt-4 text-center border-t sm:text-right shrink-0 sm:border-t-0 sm:border-l border-white/10 sm:pt-0 sm:pl-6">
                        <p class="text-xs uppercase tracking-[0.2em] text-muted font-mono">{{ __('profile.elo') }}</p>
                        <p class="mt-1 font-mono text-3xl sm:text-4xl font-bold leading-none text-gold">{{ $user->elo_rating ?? 0 }}</p>
                        <p class="mt-1 text-xs text-muted">{{ __('profile.ranking') }}</p>
                    </div>
                </div>
            </div>

            <!-- Statistik penuh (grafik, aktivitas, distribusi mode) pindah ke /stats. -->
            <a href="{{ route('stats') }}"
                class="flex items-center justify-between gap-4 p-5 transition-colors border bg-surface/40 border-white/5 rounded-2xl hover:border-brand/40 group">
                <div>
                    <h3 class="font-mono text-sm font-semibold text-foreground">{{ __('profile.tab.stats') }}</h3>
                    <p class="mt-1 font-mono text-xs text-muted">{{ __('profile.view_stats_hint') }}</p>
                </div>
                <svg class="w-4 h-4 transition-colors text-muted group-hover:text-brand-bright shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7" />
                </svg>
            </a>

            <!-- Tabs -->
            <div class="border-b border-white/10">
                <nav class="flex gap-6 -mb-px" aria-label="Tabs">
                    <button @click="activeTab = 'BestRecords'"
                            :class="activeTab === 'BestRecords' ? 'border-brand-bright text-brand-bright' : 'border-transparent text-muted hover:text-foreground'"
                            class="px-1 py-3 font-mono text-sm font-semibold transition-colors border-b-2 whitespace-nowrap">
                        {{ __('profile.tab.best_records') }}
                    </button>
                </nav>
            </div>

            <!-- ===== BEST RECORDS TAB ===== -->
            <div x-show="activeTab === 'BestRecords'"
                 x-transition:enter="transition ease-out duration-300"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 style="display: none;"
                 class="space-y-4"
                 x-data="{ openMode: 'time' }">
                @php
                    $timeRecords = $bestRecords->where('mode', 'time');
                    $wordsRecords = $bestRecords->where('mode', 'words');
                    $survivalRecords = $bestRecords->where('mode', 'survival');
                    $quoteRecords = $bestRecords->where('mode', 'quote');
                @endphp

                <div class="overflow-hidden border bg-surface/40 border-white/5 rounded-2xl">
                    <button @click="openMode = (openMode === 'time' ? '' : 'time')"
                            class="flex items-center justify-between w-full p-5 font-mono text-left transition-colors hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">⏱️</span>
                            <div>
                                <h4 class="text-sm font-semibold capitalize text-foreground">{{ __('profile.records.time_mode') }}</h4>
                                <p class="text-xs text-muted font-mono mt-0.5">{{ __('profile.records.time_desc') }}</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 text-muted" :class="openMode === 'time' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="openMode === 'time'" x-collapse class="px-5 pt-4 pb-5 border-t border-white/5 bg-background/20">
                        @if($timeRecords->count() > 0)
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                @foreach($timeRecords as $record)
                                    <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                        <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono">{{ $record->mode_config }} {{ __('profile.records.seconds') }}</span>
                                        <p class="mt-1 font-mono text-xl font-bold text-brand-bright tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="py-2 font-mono text-xs text-muted">{{ __('profile.records.time_empty') }}</p>
                        @endif
                    </div>
                </div>

                <div class="overflow-hidden border bg-surface/40 border-white/5 rounded-2xl">
                    <button @click="openMode = (openMode === 'words' ? '' : 'words')"
                            class="flex items-center justify-between w-full p-5 font-mono text-left transition-colors hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">🔤</span>
                            <div>
                                <h4 class="text-sm font-semibold capitalize text-foreground">{{ __('profile.records.words_mode') }}</h4>
                                <p class="text-xs text-muted font-mono mt-0.5">{{ __('profile.records.words_desc') }}</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 text-muted" :class="openMode === 'words' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="openMode === 'words'" x-collapse class="px-5 pt-4 pb-5 border-t border-white/5 bg-background/20">
                        @if($wordsRecords->count() > 0)
                            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                                @foreach($wordsRecords as $record)
                                    <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                        <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono">{{ $record->mode_config }} {{ __('profile.records.words') }}</span>
                                        <p class="mt-1 font-mono text-xl font-bold text-gold tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="py-2 font-mono text-xs text-muted">{{ __('profile.records.words_empty') }}</p>
                        @endif
                    </div>
                </div>

                <div class="overflow-hidden border bg-surface/40 border-white/5 rounded-2xl">
                    <button @click="openMode = (openMode === 'survival' ? '' : 'survival')"
                            class="flex items-center justify-between w-full p-5 font-mono text-left transition-colors hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">❤️</span>
                            <div>
                                <h4 class="text-sm font-semibold capitalize text-foreground">{{ __('profile.records.survival_mode') }}</h4>
                                <p class="text-xs text-muted font-mono mt-0.5">{{ __('profile.records.survival_desc') }}</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 text-muted" :class="openMode === 'survival' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="openMode === 'survival'" x-collapse class="px-5 pt-4 pb-5 border-t border-white/5 bg-background/20">
                        @if($survivalRecords->count() > 0)
                            <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                                @foreach($survivalRecords as $record)
                                    <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                        <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono capitalize">{{ $record->mode_config }} {{ __('profile.records.difficulty') }}</span>
                                        <p class="mt-1 font-mono text-xl font-bold text-gold tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="py-2 font-mono text-xs text-muted">{{ __('profile.records.survival_empty') }}</p>
                        @endif
                    </div>
                </div>

                @if($quoteRecords->count() > 0)
                <div class="overflow-hidden border bg-surface/40 border-white/5 rounded-2xl">
                    <button @click="openMode = (openMode === 'quote' ? '' : 'quote')"
                            class="flex items-center justify-between w-full p-5 font-mono text-left transition-colors hover:bg-white/5">
                        <div class="flex items-center gap-3">
                            <span class="text-xl">💬</span>
                            <div>
                                <h4 class="text-sm font-semibold capitalize text-foreground">{{ __('profile.records.quote_mode') }}</h4>
                                <p class="text-xs text-muted font-mono mt-0.5">{{ __('profile.records.quote_desc') }}</p>
                            </div>
                        </div>
                        <svg xmlns="http://www.w3.org/2000/svg" class="w-4 h-4 transition-transform duration-200 text-muted" :class="openMode === 'quote' ? 'rotate-180' : ''" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 9l-7 7-7-7" />
                        </svg>
                    </button>

                    <div x-show="openMode === 'quote'" x-collapse class="px-5 pt-4 pb-5 border-t border-white/5 bg-background/20">
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                            @foreach($quoteRecords as $record)
                                <div class="p-4 border bg-surface/60 border-white/5 rounded-2xl">
                                    <span class="text-[0.65rem] uppercase tracking-wider text-muted font-mono capitalize">{{ $record->mode_config }}</span>
                                    <p class="mt-1 font-mono text-xl font-bold text-brand-bright tabular-nums">{{ round($record->high_wpm) }} <span class="text-xs font-normal text-foreground">WPM</span></p>
                                </div>
                            @endforeach
                        </div>
                    </div>
                </div>
                @endif
            </div>

        </div>
    </div>

</x-app-layout>