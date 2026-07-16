<div class="py-10">
    <x-page-container>

        <div class="flex flex-wrap items-center justify-between gap-3 mb-8">
            <h1 class="font-display text-fluid-title tracking-wide text-foreground">{{ __('clan.leaderboard_title') }}</h1>
            <a href="{{ route('clans.index') }}" wire:navigate class="font-mono text-xs text-muted hover:text-foreground transition inline-flex items-center gap-2">
                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
                {{ __('clan.back_to_clan_plain') }}
            </a>
        </div>

        @if ($this->ranking->count() > 0)
            @php $podium = $this->ranking->take(3); $rest = $this->ranking->slice(3); @endphp

            {{-- ===== PODIUM TOP 3 ===== --}}
            <div class="grid grid-cols-1 sm:grid-cols-3 gap-4 mb-8 sm:items-end">
                @foreach ($podium as $i => $row)
                    @php
                        $clan = $row['clan'];
                        $isMine = $clan->id === $this->myClanId;
                        $medal = ['🥇', '🥈', '🥉'][$i];
                        $accent = $i === 0 ? 'border-gold/50 bg-gold/10' : 'border-white/10 bg-surface/60';
                        $order = ['sm:order-2', 'sm:order-1', 'sm:order-3'][$i];
                        $lift = $i === 0 ? 'sm:pb-8' : 'sm:pb-4';
                    @endphp
                    <a href="{{ route('clans.show', $clan) }}" wire:navigate wire:key="podium-{{ $clan->id }}"
                        class="group relative flex flex-col items-center text-center gap-3 p-5 {{ $lift }} border rounded-2xl transition hover:-translate-y-0.5 {{ $order }} {{ $accent }} {{ $isMine ? 'ring-1 ring-gold/40' : '' }}">
                        <span class="absolute top-3 left-4 text-2xl">{{ $medal }}</span>
                        <x-clan-emblem :clan="$clan" size="{{ $i === 0 ? 'lg' : 'md' }}" class="mt-2" />
                        <div class="min-w-0 w-full">
                            <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">
                                {{ $clan->name }}
                                @if ($clan->tag)<span class="text-muted font-normal">[{{ $clan->tag }}]</span>@endif
                            </p>
                            <p class="font-mono text-[0.65rem] uppercase tracking-wider text-muted mt-1">
                                {{ __('clan.leaderboard_row.stats', ['level' => $clan->levelData()['level'], 'members' => $clan->members_count, 'wins' => $row['wins']]) }}
                            </p>
                        </div>
                        <p class="font-mono text-2xl font-bold text-gold tabular-nums leading-none">{{ number_format($clan->power) }}</p>
                        <p class="font-mono text-[0.55rem] uppercase tracking-widest text-muted -mt-2">{{ __('clan.power') }}</p>
                        @if ($isMine)
                            <span class="font-mono text-[0.55rem] uppercase tracking-wider text-gold">{{ __('clan.leaderboard_row.your_clan') }}</span>
                        @endif
                    </a>
                @endforeach
            </div>

            {{-- ===== SISA PERINGKAT ===== --}}
            @if ($rest->count() > 0)
                <div class="space-y-2">
                    @foreach ($rest as $i => $row)
                        @php $clan = $row['clan']; $isMine = $clan->id === $this->myClanId; @endphp
                        <a href="{{ route('clans.show', $clan) }}" wire:navigate wire:key="rank-{{ $clan->id }}"
                            @class([
                                'flex items-center gap-4 p-3.5 border rounded-2xl transition group',
                                'bg-gold/10 border-gold/40' => $isMine,
                                'bg-surface/40 border-white/5 hover:border-white/10' => ! $isMine,
                            ])>
                            <span class="font-mono text-sm font-bold text-muted w-6 text-center shrink-0 tabular-nums">{{ $i + 4 }}</span>
                            <x-clan-emblem :clan="$clan" size="sm" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">
                                    {{ $clan->name }}
                                    @if ($clan->tag)<span class="text-muted font-normal">[{{ $clan->tag }}]</span>@endif
                                    @if ($isMine)<span class="ml-1 text-[0.6rem] uppercase tracking-wider text-gold">{{ __('clan.leaderboard_row.your_clan_dot') }}</span>@endif
                                </p>
                                <p class="font-mono text-xs text-muted mt-0.5">
                                    {{ __('clan.leaderboard_row.stats', ['level' => $clan->levelData()['level'], 'members' => $clan->members_count, 'wins' => $row['wins']]) }}
                                </p>
                            </div>
                            <div class="text-right shrink-0">
                                <p class="font-mono text-lg font-bold text-gold tabular-nums leading-none">{{ number_format($clan->power) }}</p>
                                <p class="font-mono text-[0.55rem] uppercase tracking-wider text-muted mt-1">{{ __('clan.power') }}</p>
                            </div>
                        </a>
                    @endforeach
                </div>
            @endif
        @else
            <div class="flex flex-col items-center justify-center py-24 text-center select-none">
                <img src="/icon/uetype_mascot.png" alt="" class="w-16 h-16 opacity-30 mb-4">
                <p class="font-mono text-sm font-bold text-foreground">{{ __('clan.empty.leaderboard_title') }}</p>
                <p class="font-mono text-xs text-muted mt-1">{{ __('clan.empty.leaderboard_body') }}</p>
            </div>
        @endif
    </x-page-container>
</div>
