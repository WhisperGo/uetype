<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <div class="flex items-center justify-between mb-2">
        <h1 class="font-display text-2xl tracking-wide text-foreground">CLAN WAR</h1>
        @if ($this->myClan)
            <a href="{{ route('clans.index') }}" wire:navigate class="font-mono text-xs text-muted hover:text-foreground transition">
                ← Back to Clan
            </a>
        @endif
    </div>

    @if (! $this->myClan)
        <div class="flex flex-col items-center justify-center py-24 text-center select-none">
            <img src="/icon/uetype_mascot.png" alt="" class="w-16 h-16 opacity-30 mb-4">
            <p class="font-mono text-sm font-bold text-foreground">Join a clan first</p>
            <p class="font-mono text-xs text-muted mt-1">Clan War hanya bisa diikuti kalau kamu sudah tergabung di sebuah clan</p>
            <a href="{{ route('clans.index') }}" wire:navigate
                class="mt-5 px-5 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                Go to Clans
            </a>
        </div>
    @else
        <p class="font-mono text-xs text-muted mb-6">
            {{ $this->myClan->name }} · Power <span class="text-gold font-bold">{{ number_format($this->myClan->power) }}</span>
        </p>

        {{-- ================= TANTANGAN MASUK (khusus leader) ================= --}}
        @if ($this->incomingChallenge)
            @php $war = $this->incomingChallenge; @endphp
            <div class="p-5 border bg-surface/40 border-gold/30 rounded-2xl mb-6">
                <p class="font-mono text-xs uppercase tracking-widest text-gold mb-2">Incoming Challenge</p>
                <p class="font-mono text-sm text-foreground">
                    <span class="font-bold">{{ $war->challenger->name }}</span>
                    (power {{ number_format($war->challenger->power) }}) menantang clan-mu.
                </p>
                <p class="font-mono text-xs text-muted mt-1">
                    Harus direspons sebelum {{ $war->accept_deadline_at->translatedFormat('d M Y H:i') }}
                </p>
                <div class="flex gap-3 mt-4">
                    <button wire:click="acceptChallenge({{ $war->id }})"
                        class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                        Accept
                    </button>
                    <button wire:click="declineChallenge({{ $war->id }})"
                        class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                        Decline
                    </button>
                </div>
            </div>

        {{-- ================= WAR SEDANG DITANTANGKAN (menunggu accept lawan) ================= --}}
        @elseif ($this->myActiveWar && $this->myActiveWar->status->value === 'pending')
            @php $war = $this->myActiveWar; @endphp
            <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl mb-6">
                <p class="font-mono text-xs uppercase tracking-widest text-muted mb-2">Waiting for response</p>
                <p class="font-mono text-sm text-foreground">
                    Menunggu <span class="font-bold">{{ $war->opponent->name }}</span>
                    (power {{ number_format($war->opponent->power) }}) merespons tantanganmu.
                </p>
                <p class="font-mono text-xs text-muted mt-1">
                    Hangus otomatis {{ $war->accept_deadline_at->translatedFormat('d M Y H:i') }} kalau tidak direspons
                </p>
            </div>

        {{-- ================= WAR SEDANG BERJALAN ================= --}}
        @elseif ($this->myActiveWar && $this->myActiveWar->status->value === 'ongoing')
            @php
                $war = $this->myActiveWar;
                $opponent = $war->challenger_clan_id === $this->myClan->id ? $war->opponent : $war->challenger;
            @endphp
            <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl mb-6">
                <p class="font-mono text-xs uppercase tracking-widest text-muted mb-2">War Ongoing</p>
                <p class="font-mono text-sm text-foreground">
                    vs <span class="font-bold">{{ $opponent->name }}</span>
                    (power {{ number_format($opponent->power) }})
                </p>
                <p class="font-mono text-xs text-muted mt-1">
                    Berakhir {{ $war->ends_at->translatedFormat('d M Y H:i') }}
                </p>
            </div>

            @if ($this->myClanBreakdown->count() > 0)
                <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Your Clan's Contributors (live)</p>
                <div class="space-y-3 mb-8">
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

        {{-- ================= BEBAS: BISA MENANTANG (khusus leader) ================= --}}
        @elseif ($this->isLeader)
            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Challenge a Clan</p>
            @if ($this->challengeableClans->count() > 0)
                <div class="space-y-3 mb-8">
                    @foreach ($this->challengeableClans as $clan)
                        <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl" wire:key="challenge-{{ $clan->id }}">
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate">
                                    {{ $clan->name }}
                                    @if ($clan->tag)
                                        <span class="text-muted font-normal">[{{ $clan->tag }}]</span>
                                    @endif
                                </p>
                                <p class="font-mono text-xs text-muted mt-0.5">power {{ number_format($clan->power) }}</p>
                            </div>
                            <button wire:click="challengeClan({{ $clan->id }})"
                                class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                                Challenge
                            </button>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="font-mono text-sm text-muted py-6 mb-8">No clans available to challenge right now.</p>
            @endif
        @else
            <p class="font-mono text-sm text-muted py-6 mb-8">Your clan is not in a war right now. Ask your leader to challenge another clan.</p>
        @endif

        {{-- ================= RIWAYAT WAR ================= --}}
        @if ($this->warHistory->count() > 0)
            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">War History</p>
            <div class="space-y-3">
                @foreach ($this->warHistory as $war)
                    @php
                        $isChallenger = $war->challenger_clan_id === $this->myClan->id;
                        $opponent = $isChallenger ? $war->opponent : $war->challenger;
                        $myDelta = $isChallenger ? $war->challenger_power_delta : $war->opponent_power_delta;

                        // 'result' tersimpan dari sudut pandang challenger; balik kalau kita opponent.
                        if ($war->result === 'draw') {
                            $myResult = 'draw';
                        } elseif ($isChallenger) {
                            $myResult = $war->result; // 'win' atau 'loss' apa adanya
                        } else {
                            $myResult = $war->result === 'win' ? 'loss' : 'win';
                        }
                    @endphp
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl">
                        <span class="font-mono text-xs font-bold uppercase w-12 shrink-0
                            {{ $myResult === 'win' ? 'text-gold' : ($myResult === 'draw' ? 'text-muted' : 'text-red-400/80') }}">
                            {{ $myResult }}
                        </span>
                        <div class="flex-1 min-w-0">
                            <p class="font-mono text-sm text-foreground truncate">vs {{ $opponent->name }}</p>
                        </div>
                        <span class="font-mono text-sm font-bold tabular-nums {{ $myDelta >= 0 ? 'text-gold' : 'text-red-400/80' }}">
                            {{ $myDelta >= 0 ? '+' : '' }}{{ $myDelta }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ===== REAL-TIME (reuse subscriber toast global di layout) ===== --}}
    @script
        <script>
            const onRemote = () => $wire.dispatch('clan-updated');
            window.addEventListener('clan-updated-remote', onRemote);

            document.addEventListener('livewire:navigating', () => {
                window.removeEventListener('clan-updated-remote', onRemote);
            }, { once: true });
        </script>
    @endscript
</div>
