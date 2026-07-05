<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <h1 class="font-display text-2xl tracking-wide text-foreground mb-2">CLAN WAR</h1>

    @if (! $this->currentWar)
        <div class="flex flex-col items-center justify-center py-24 text-center select-none">
            <img src="/icon/uetype_mascot.png" alt="" class="w-16 h-16 opacity-30 mb-4">
            <p class="font-mono text-sm font-bold text-foreground">No clan war yet</p>
            <p class="font-mono text-xs text-muted mt-1">Check back once the next war period begins</p>
        </div>
    @else
        <p class="font-mono text-xs text-muted mb-6">
            @if ($this->currentWar->status->value === 'ongoing')
                Ongoing · ends {{ $this->currentWar->ends_at->translatedFormat('d M Y H:i') }}
            @else
                Finished · {{ $this->currentWar->starts_at->translatedFormat('d M') }} – {{ $this->currentWar->ends_at->translatedFormat('d M Y') }}
            @endif
        </p>

        <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Standings</p>
        <div class="space-y-3 mb-10">
            @forelse ($this->standings as $i => $row)
                <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl">
                    <span class="font-mono text-lg font-bold w-8 text-center {{ $i === 0 ? 'text-gold' : 'text-muted' }}">
                        {{ $row['placement'] ?? ($i + 1) }}
                    </span>
                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-sm font-bold text-foreground truncate">
                            {{ $row['clan']->name }}
                            @if ($row['clan']->tag)
                                <span class="text-muted font-normal">[{{ $row['clan']->tag }}]</span>
                            @endif
                        </p>
                    </div>
                    <span class="font-mono text-sm font-bold text-brand-bright tabular-nums">{{ number_format($row['total']) }} XP</span>
                </div>
            @empty
                <p class="font-mono text-sm text-muted py-6">No clans have participated yet.</p>
            @endforelse
        </div>

        @if ($this->myClanBreakdown->count() > 0)
            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Your Clan's Contributors</p>
            <div class="space-y-3">
                @foreach ($this->myClanBreakdown as $row)
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl">
                        <x-friend-avatar :user="$row['user']" />
                        <div class="flex-1 min-w-0">
                            <p class="font-mono text-sm font-bold text-foreground truncate">{{ $row['user']->username }}</p>
                        </div>
                        <span class="font-mono text-sm font-bold text-gold tabular-nums">{{ number_format($row['total']) }} XP</span>
                    </div>
                @endforeach
            </div>
        @endif
    @endif
</div>
