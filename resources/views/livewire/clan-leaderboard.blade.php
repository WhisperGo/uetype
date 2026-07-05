<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <div class="flex items-center justify-between mb-6">
        <h1 class="font-display text-2xl tracking-wide text-foreground">CLAN LEADERBOARD</h1>
        <a href="{{ route('clans.index') }}" wire:navigate class="font-mono text-xs text-muted hover:text-foreground transition">
            ← Back to Clan
        </a>
    </div>

    @if ($this->ranking->count() > 0)
        <div class="space-y-2">
            @foreach ($this->ranking as $i => $row)
                <a href="{{ route('clans.show', $row['clan']) }}" wire:navigate
                    @class([
                        'flex items-center gap-4 p-4 border rounded-2xl transition group',
                        'bg-gold/10 border-gold/40' => $row['clan']->id === $this->myClanId,
                        'bg-surface/40 border-white/5 hover:border-white/10' => $row['clan']->id !== $this->myClanId,
                    ])
                    wire:key="rank-{{ $row['clan']->id }}">
                    <span @class([
                        'font-mono text-lg font-bold w-8 text-center shrink-0',
                        'text-gold' => $i === 0,
                        'text-muted' => $i !== 0,
                    ])>{{ $i + 1 }}</span>

                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">
                            {{ $row['clan']->name }}
                            @if ($row['clan']->tag)
                                <span class="text-muted font-normal">[{{ $row['clan']->tag }}]</span>
                            @endif
                            @if ($row['clan']->id === $this->myClanId)
                                <span class="ml-1 text-[0.6rem] uppercase tracking-wider text-gold">· your clan</span>
                            @endif
                        </p>
                        <p class="font-mono text-xs text-muted mt-0.5">
                            {{ $row['clan']->members_count }} members · {{ $row['wins'] }} wins
                        </p>
                    </div>

                    <div class="text-right shrink-0">
                        <p class="font-mono text-lg font-bold text-gold tabular-nums">{{ number_format($row['clan']->power) }}</p>
                        <p class="font-mono text-[0.6rem] uppercase tracking-wider text-muted">power</p>
                    </div>
                </a>
            @endforeach
        </div>
    @else
        <div class="flex flex-col items-center justify-center py-24 text-center select-none">
            <img src="/icon/uetype_mascot.png" alt="" class="w-16 h-16 opacity-30 mb-4">
            <p class="font-mono text-sm font-bold text-foreground">No clans yet</p>
            <p class="font-mono text-xs text-muted mt-1">Create a clan to appear on the leaderboard</p>
        </div>
    @endif
</div>
