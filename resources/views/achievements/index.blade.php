<x-app-layout>
    <x-slot name="header">
        <h2 class="font-sans text-xl font-semibold tracking-tight text-foreground">
            {{ __('achievements.header') }}
        </h2>
    </x-slot>

    @php
        $progressPercent = $total > 0 ? ($earnedCount / $total) * 100 : 0;
    @endphp

    <div class="py-10"
        x-data="{
            filter: 'all',
            visible(cat) { return this.filter === 'all' || this.filter === cat; }
        }">
        <div class="max-w-5xl px-4 mx-auto space-y-6 sm:px-6 lg:px-8">

            <!-- Back to Profile -->
            <a href="{{ route('profile.edit') }}"
                class="inline-flex items-center gap-1.5 font-mono text-sm text-muted hover:text-foreground transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                {{ __('achievements.back_to_profile') }}
            </a>

            <!-- Heading + progress -->
            <div class="space-y-3">
                <div>
                    <h1 class="font-sans text-3xl font-bold text-foreground">{{ __('achievements.header') }}</h1>
                    <p class="mt-1 font-mono text-sm text-muted">
                        {{ __('achievements.unlocked_count', ['count' => $earnedCount, 'total' => $total]) }}
                    </p>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-white/5">
                    <div class="h-full rounded-full bg-gradient-to-r from-brand to-gold transition-all duration-500"
                        style="width: {{ $progressPercent }}%"></div>
                </div>
            </div>

            <!-- Category filters -->
            <div class="flex flex-wrap gap-2">
                @foreach ($categories as $cat)
                    <button type="button"
                        @click="filter = '{{ $cat['key'] }}'"
                        :class="filter === '{{ $cat['key'] }}'
                            ? 'bg-brand-bright text-background border-brand-bright'
                            : 'bg-surface/60 text-muted border-white/10 hover:text-foreground hover:border-white/20'"
                        class="px-4 py-1.5 rounded-lg border font-sans text-xs font-semibold transition-colors">
                        {{ __('achievements.categories.'.$cat['key']) }}
                    </button>
                @endforeach
            </div>

            <!-- Achievement grid -->
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3">
                @foreach ($achievements as $a)
                    <div x-show="visible('{{ $a['category'] }}')" x-transition.opacity
                        class="flex items-center gap-4 p-4 border rounded-2xl transition
                            {{ $a['earned']
                                ? 'bg-surface/60 border-white/10'
                                : 'bg-surface/20 border-white/5 opacity-50' }}">

                        <!-- Icon badge -->
                        <div class="flex flex-col items-center justify-center w-14 h-14 rounded-xl shrink-0 border
                            {{ $a['earned']
                                ? 'bg-brand/15 border-brand/40 text-brand-bright'
                                : 'bg-white/5 border-white/10 text-muted' }}">
                            <span class="font-mono text-sm font-bold leading-none">{{ $a['icon_value'] }}</span>
                            <span class="mt-0.5 text-[0.55rem] font-mono uppercase tracking-wider {{ $a['earned'] ? 'text-brand-bright/70' : 'text-muted/60' }}">
                                {{ $a['icon_unit'] }}
                            </span>
                        </div>

                        <!-- Text -->
                        <div class="min-w-0">
                            <h3 class="font-sans text-sm font-bold text-foreground truncate">{{ __('achievements.defs.'.$a['key'].'.title') }}</h3>
                            <p class="mt-0.5 font-mono text-xs text-muted truncate">{{ __('achievements.defs.'.$a['key'].'.description') }}</p>
                            @if ($a['earned'])
                                <p class="mt-1 flex items-center gap-1 font-mono text-[0.7rem] text-gold">
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                    </svg>
                                    {{ __('achievements.earned') }}
                                    @if ($a['unlocked_at'])
                                        <span class="text-muted/70">· @localtime($a['unlocked_at'], 'd M Y')</span>
                                    @endif
                                </p>
                            @else
                                <p class="mt-1 font-mono text-[0.7rem] text-muted/60">{{ __('achievements.locked') }}</p>
                            @endif
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
    </div>
</x-app-layout>
