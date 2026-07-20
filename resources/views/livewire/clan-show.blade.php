<div class="py-10">
    <x-page-container>

    <div class="flex items-center justify-between mb-6">
        <a href="{{ route('clan-leaderboard.index') }}" wire:navigate class="text-muted hover:text-foreground transition inline-flex items-center gap-2" aria-label="{{ __('clan.aria.back_leaderboard') }}">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            <span class="font-mono text-xs">{{ __('clan.leaderboard') }}</span>
        </a>
    </div>

    {{-- ===== HEADER CLAN (banner ber-identitas) ===== --}}
    @php $lvl = $clan->levelData(); @endphp
    <div class="relative overflow-hidden p-6 sm:p-8 border bg-surface/70 border-white/10 rounded-3xl mb-8">
        <div class="pointer-events-none absolute -top-16 -right-16 w-56 h-56 rounded-full bg-gold/5 blur-2xl"></div>
        <div class="relative flex flex-col sm:flex-row sm:items-center gap-5">
            <x-clan-emblem :clan="$clan" size="lg" />

            <div class="flex-1 min-w-0">
                <h1 class="font-display text-fluid-title tracking-wide text-foreground leading-tight">
                    {{ $clan->name }}
                    @if ($clan->tag)<span class="text-muted text-lg">[{{ $clan->tag }}]</span>@endif
                </h1>
                @if ($clan->description)
                    <p class="font-mono text-sm text-muted mt-1.5 max-w-xl">{{ $clan->description }}</p>
                @endif
                <div class="flex flex-wrap items-center gap-2 mt-3">
                    <span class="px-2.5 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg">{{ __('clan.level', ['level' => $lvl['level']]) }}</span>
                    <span class="font-mono text-xs text-muted">{{ __('clan.members_count', ['count' => $this->members->count()]) }}</span>
                </div>
            </div>

            <div class="text-left sm:text-right shrink-0">
                <p class="font-mono text-3xl sm:text-4xl font-bold leading-none text-gold tabular-nums">{{ number_format($clan->power) }}</p>
                <p class="font-mono text-[0.6rem] uppercase tracking-wider text-muted mt-1">{{ __('clan.power') }}</p>
            </div>
        </div>

        {{-- Bar progres level (progress menuju level berikutnya, dari power) --}}
        <x-clan.progress :data="$lvl" class="mt-6" />
    </div>

    {{-- ===== MEMBERS ===== --}}
    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('clan.members_heading', ['count' => $this->members->count()]) }}</p>
    <div class="space-y-3 mb-10">
        @foreach ($this->members as $member)
            <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group hover:border-white/10 transition {{ $member->role->value === 'leader' ? 'ring-1 ring-gold/20' : '' }}" wire:key="member-{{ $member->id }}">
                <a href="{{ route('profile.show', $member->user) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                    <x-friend-avatar :user="$member->user" />
                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $member->user->username }}</p>
                        <p class="font-mono text-xs text-muted mt-0.5">{{ __('clan.user_level', ['level' => $member->user->levelData()['level']]) }}</p>
                    </div>
                </a>
                <x-clan.role-badge :role="$member->role" />
            </div>
        @endforeach
    </div>

    {{-- ===== MATCH HISTORY ===== --}}
    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('clan.war.match_history') }}</p>
    @if ($this->history->count() > 0)
        <div class="space-y-3">
            @foreach ($this->history as $row)
                @php
                    $result = $row['result'];
                    $win = $result === 'win';
                    $draw = $result === 'draw';
                    $accent = $win ? 'text-gold border-gold/40 bg-gold/5' : ($draw ? 'text-muted border-white/10' : 'text-danger border-danger/30 bg-danger/5');
                @endphp
                <div class="flex items-center gap-4 p-4 border rounded-2xl {{ $win ? 'border-gold/20' : ($draw ? 'border-white/5' : 'border-danger/20') }} bg-surface/40" wire:key="war-{{ $row['war']->id }}">
                    <span class="w-14 shrink-0 text-center px-2 py-1.5 font-mono text-[0.7rem] font-bold uppercase tracking-wider border rounded-lg {{ $accent }}">
                        {{ __('clan.result.'.$result) }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-sm text-foreground truncate">
                            {{ __('clan.war.vs_label') }}
                            <a href="{{ route('clans.show', $row['opponent']) }}" wire:navigate class="font-bold hover:text-gold transition-colors">{{ $row['opponent']->name }}</a>
                        </p>
                        <p class="font-mono text-[0.65rem] text-muted mt-0.5">@localtime($row['war']->updated_at, 'd M Y')</p>
                    </div>
                    <span class="font-mono text-sm font-bold tabular-nums shrink-0 {{ $row['delta'] >= 0 ? 'text-gold' : 'text-danger' }}">
                        {{ $row['delta'] >= 0 ? '+' : '' }}{{ $row['delta'] }} <span class="text-[0.6rem] text-muted font-normal">{{ __('clan.power') }}</span>
                    </span>
                </div>
            @endforeach
        </div>
    @else
        <x-empty-state card spacing="16" :body="__('clan.empty.history')" />
    @endif
    </x-page-container>
</div>
