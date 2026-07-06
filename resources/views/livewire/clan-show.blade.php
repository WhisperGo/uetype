<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <div class="flex items-center justify-between mb-6">
        <a href="{{ route('clan-leaderboard.index') }}" wire:navigate class="text-muted hover:text-foreground transition inline-flex items-center gap-2" aria-label="Kembali ke leaderboard">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            <span class="font-mono text-xs">Leaderboard</span>
        </a>
    </div>

    {{-- ===== HEADER CLAN ===== --}}
    <div class="p-6 border bg-surface/70 border-white/10 rounded-3xl mb-8">
        <div class="flex items-center justify-between flex-wrap gap-4">
            <div>
                <h1 class="font-display text-2xl tracking-wide text-foreground">
                    {{ $clan->name }}
                    @if ($clan->tag)
                        <span class="text-muted text-lg">[{{ $clan->tag }}]</span>
                    @endif
                </h1>
                <p class="font-mono text-xs text-muted mt-1">{{ $this->members->count() }} members</p>
            </div>
            <div class="text-right">
                <p class="font-mono text-4xl font-bold leading-none text-gold tabular-nums">{{ number_format($clan->power) }}</p>
                <p class="font-mono text-[0.6rem] uppercase tracking-wider text-muted mt-1">power</p>
            </div>
        </div>
    </div>

    {{-- ===== MEMBERS ===== --}}
    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Members ({{ $this->members->count() }})</p>
    <div class="space-y-3 mb-10">
        @foreach ($this->members as $member)
            <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group" wire:key="member-{{ $member->id }}">
                <a href="{{ route('profile.show', $member->user) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                    <x-friend-avatar :user="$member->user" />
                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $member->user->username }}</p>
                        <p class="font-mono text-xs text-muted mt-0.5">level {{ $member->user->levelData()['level'] }}</p>
                    </div>
                </a>
                @if ($member->role->value === 'leader')
                    <span class="px-3 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg shrink-0">Leader</span>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ===== MATCH HISTORY ===== --}}
    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Match History</p>
    @if ($this->history->count() > 0)
        <div class="space-y-3">
            @foreach ($this->history as $row)
                <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl" wire:key="war-{{ $row['war']->id }}">
                    <span class="font-mono text-xs font-bold uppercase w-12 shrink-0
                        {{ $row['result'] === 'win' ? 'text-gold' : ($row['result'] === 'draw' ? 'text-muted' : 'text-red-400/80') }}">
                        {{ $row['result'] }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-sm text-foreground truncate">
                            vs
                            <a href="{{ route('clans.show', $row['opponent']) }}" wire:navigate class="hover:text-gold transition-colors">{{ $row['opponent']->name }}</a>
                        </p>
                        <p class="font-mono text-[0.65rem] text-muted mt-0.5">@localtime($row['war']->updated_at, 'd M Y')</p>
                    </div>
                    <span class="font-mono text-sm font-bold tabular-nums shrink-0 {{ $row['delta'] >= 0 ? 'text-gold' : 'text-red-400/80' }}">
                        {{ $row['delta'] >= 0 ? '+' : '' }}{{ $row['delta'] }} <span class="text-[0.6rem] text-muted font-normal">power</span>
                    </span>
                </div>
            @endforeach
        </div>
    @else
        <p class="font-mono text-sm text-muted py-6">Belum ada riwayat war yang selesai.</p>
    @endif
</div>
