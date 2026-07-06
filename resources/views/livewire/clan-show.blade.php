<div class="py-10">
    <x-page-container>

    <div class="flex items-center justify-between mb-6">
        <a href="{{ route('clan-leaderboard.index') }}" wire:navigate class="text-muted hover:text-foreground transition inline-flex items-center gap-2" aria-label="Kembali ke leaderboard">
            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" /></svg>
            <span class="font-mono text-xs">Leaderboard</span>
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
                    <span class="px-2.5 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg">Lv {{ $lvl['level'] }}</span>
                    <span class="font-mono text-xs text-muted">{{ $this->members->count() }} members</span>
                </div>
            </div>

            <div class="text-left sm:text-right shrink-0">
                <p class="font-mono text-3xl sm:text-4xl font-bold leading-none text-gold tabular-nums">{{ number_format($clan->power) }}</p>
                <p class="font-mono text-[0.6rem] uppercase tracking-wider text-muted mt-1">power</p>
            </div>
        </div>

        {{-- Bar progres level (progress menuju level berikutnya, dari power) --}}
        <div class="relative mt-6">
            <div class="flex justify-between font-mono text-[0.6rem] uppercase tracking-wider text-muted mb-1.5">
                <span>Lv {{ $lvl['level'] }}</span>
                <span>{{ $lvl['progress'] }} / {{ $lvl['needed'] }}</span>
                <span>Lv {{ $lvl['next_level'] }}</span>
            </div>
            <div class="h-2 w-full rounded-full bg-white/5 overflow-hidden">
                <div class="h-full rounded-full bg-gold transition-all" style="width: {{ $lvl['needed'] > 0 ? min(100, round($lvl['progress'] / $lvl['needed'] * 100)) : 0 }}%"></div>
            </div>
        </div>
    </div>

    {{-- ===== MEMBERS ===== --}}
    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Members ({{ $this->members->count() }})</p>
    <div class="space-y-3 mb-10">
        @foreach ($this->members as $member)
            <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group hover:border-white/10 transition {{ $member->role->value === 'leader' ? 'ring-1 ring-gold/20' : '' }}" wire:key="member-{{ $member->id }}">
                <a href="{{ route('profile.show', $member->user) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                    <x-friend-avatar :user="$member->user" />
                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $member->user->username }}</p>
                        <p class="font-mono text-xs text-muted mt-0.5">level {{ $member->user->levelData()['level'] }}</p>
                    </div>
                </a>
                @if ($member->role->value === 'leader')
                    <span class="px-3 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg shrink-0 inline-flex items-center gap-1.5">
                        <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M4 8l3.5 3L12 5l4.5 6L20 8l-1.5 10h-13L4 8z" /></svg>
                        Leader
                    </span>
                @endif
            </div>
        @endforeach
    </div>

    {{-- ===== MATCH HISTORY ===== --}}
    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Match History</p>
    @if ($this->history->count() > 0)
        <div class="space-y-3">
            @foreach ($this->history as $row)
                @php
                    $result = $row['result'];
                    $win = $result === 'win';
                    $draw = $result === 'draw';
                    $accent = $win ? 'text-gold border-gold/40 bg-gold/5' : ($draw ? 'text-muted border-white/10' : 'text-red-400/80 border-red-900/40 bg-red-950/10');
                @endphp
                <div class="flex items-center gap-4 p-4 border rounded-2xl {{ $win ? 'border-gold/20' : ($draw ? 'border-white/5' : 'border-red-900/20') }} bg-surface/40" wire:key="war-{{ $row['war']->id }}">
                    <span class="w-14 shrink-0 text-center px-2 py-1.5 font-mono text-[0.7rem] font-bold uppercase tracking-wider border rounded-lg {{ $accent }}">
                        {{ $result }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-sm text-foreground truncate">
                            vs
                            <a href="{{ route('clans.show', $row['opponent']) }}" wire:navigate class="font-bold hover:text-gold transition-colors">{{ $row['opponent']->name }}</a>
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
        <div class="flex flex-col items-center justify-center py-16 text-center select-none border border-white/5 rounded-2xl bg-surface/20">
            <img src="/icon/uetype_mascot.png" alt="" class="w-14 h-14 opacity-25 mb-3">
            <p class="font-mono text-sm text-muted">Belum ada riwayat war yang selesai.</p>
        </div>
    @endif
    </x-page-container>
</div>
