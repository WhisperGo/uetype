<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
        <h1 class="font-display text-fluid-title tracking-wide text-foreground">CLAN WAR</h1>
        <div class="flex items-center gap-4">
            <a href="{{ route('clan-leaderboard.index') }}" wire:navigate class="font-mono text-xs text-muted hover:text-foreground transition">
                Leaderboard
            </a>
            @if ($this->myClan)
                <a href="{{ route('clans.index') }}" wire:navigate class="font-mono text-xs text-muted hover:text-foreground transition">
                    ← Back to Clan
                </a>
            @endif
        </div>
    </div>

    @if (! $this->myClan)
        <div class="flex flex-col items-center justify-center py-24 text-center select-none">
            <img src="/icon/uetype_mascot.png" alt="" class="w-16 opacity-30 mb-4">
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
                    Harus direspons sebelum @localtime($war->accept_deadline_at, 'd M Y H:i')
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
                    Hangus otomatis @localtime($war->accept_deadline_at, 'd M Y H:i') kalau tidak direspons
                </p>
            </div>

        {{-- ================= WAR SEDANG BERJALAN ================= --}}
        @elseif ($this->myActiveWar && $this->myActiveWar->status->value === 'ongoing')
            @php
                $war = $this->myActiveWar;
                $opponent = $war->challenger_clan_id === $this->myClan->id ? $war->opponent : $war->challenger;
            @endphp
            <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl mb-6">
                <div class="flex items-center justify-between flex-wrap gap-2">
                    <div>
                        <p class="font-mono text-xs uppercase tracking-widest text-muted mb-2">War Ongoing</p>
                        <p class="font-mono text-sm text-foreground">
                            vs <span class="font-bold">{{ $opponent->name }}</span>
                            (power {{ number_format($opponent->power) }})
                        </p>
                        <p class="font-mono text-xs text-muted mt-1">
                            Berakhir @localtime($war->ends_at, 'd M Y H:i')
                        </p>
                    </div>
                    <div class="text-right">
                        <p class="font-mono text-xs uppercase tracking-widest text-muted">Your Points</p>
                        <p class="font-mono text-2xl sm:text-3xl font-bold text-gold tabular-nums">{{ rtrim(rtrim(number_format($this->myClanPoints, 1), '0'), '.') }}</p>
                    </div>
                </div>
            </div>

            @if (session('clan_war_claim_error'))
                <p class="font-mono text-xs text-red-400 mb-4">{{ session('clan_war_claim_error') }}</p>
            @endif

            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-1">War Modes</p>
            <p class="font-mono text-xs text-muted mb-3">Klaim mode kosong lalu kerjakan. Tiap mode hanya bisa dikerjakan sekali oleh clan-mu.</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-8">
                @foreach ($this->modeGrid as $slot)
                    @php
                        $labelMode = $slot['mode'] === 'survival' ? 'Survival' : ($slot['mode'] === 'time' ? 'Time' : 'Words');
                        $labelConfig = $slot['mode'] === 'survival' ? ucfirst($slot['config']) : ($slot['mode'] === 'time' ? $slot['config'].'s' : $slot['config'].' kata');
                    @endphp
                    <div class="p-4 border rounded-2xl flex flex-col gap-3
                        {{ $slot['status'] === 'done' ? 'bg-gold/5 border-gold/30' : 'bg-surface/40 border-white/5' }}"
                        wire:key="slot-{{ $slot['mode'] }}-{{ $slot['config'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate">{{ $labelMode }} · {{ $labelConfig }}</p>
                                <p class="font-mono text-[0.65rem] text-muted mt-0.5">maks {{ $slot['ceiling'] }} poin</p>
                            </div>
                            @if ($slot['status'] === 'done')
                                <span class="font-mono text-sm font-bold text-gold tabular-nums shrink-0">{{ rtrim(rtrim(number_format($slot['claim']->points, 1), '0'), '.') }}</span>
                            @endif
                        </div>

                        @switch($slot['status'])
                            @case('open')
                                <button wire:click="claimMode('{{ $slot['mode'] }}', '{{ $slot['config'] }}')"
                                    class="w-full px-3 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                                    Claim &amp; Play
                                </button>
                                @break

                            @case('claimed')
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[0.7rem] text-muted truncate">diklaim {{ $slot['claim']->user->username }}</span>
                                    @if ($slot['claim']->user_id === auth()->id())
                                        <a href="{{ route('typing', ['war_claim' => $slot['claim']->id]) }}" wire:navigate
                                            class="px-2.5 py-1 font-mono text-[0.7rem] font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition shrink-0">Play</a>
                                    @endif
                                </div>
                                @if ($slot['claim']->user_id === auth()->id() || $this->isLeader)
                                    <button wire:click="cancelClaim({{ $slot['claim']->id }})"
                                        class="w-full px-3 py-1.5 font-mono text-[0.7rem] text-red-400/80 border border-red-900/40 rounded-lg hover:bg-red-950/30 transition">
                                        Cancel Claim
                                    </button>
                                @endif
                                @break

                            @default
                                {{-- Sudah selesai: tampilkan pengerja + HASIL KETIK ASLI di balik poinnya. --}}
                                @php $tr = $slot['claim']->typingResult; @endphp
                                <div class="flex flex-col gap-1.5">
                                    <span class="font-mono text-[0.7rem] text-muted truncate">✓ {{ $slot['claim']->user->username }}</span>
                                    @if ($tr)
                                        <div class="flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-[0.65rem] text-muted border-t border-white/5 pt-1.5">
                                            @if ($slot['mode'] === 'survival')
                                                <span>bertahan <span class="text-foreground font-bold">{{ rtrim(rtrim(number_format($tr->duration_seconds, 1), '0'), '.') }}s</span></span>
                                            @else
                                                <span>wpm <span class="text-foreground font-bold">{{ rtrim(rtrim(number_format($tr->net_wpm, 1), '0'), '.') }}</span></span>
                                            @endif
                                            <span>akurasi <span class="text-foreground font-bold">{{ rtrim(rtrim(number_format($tr->accuracy, 1), '0'), '.') }}%</span></span>
                                        </div>
                                    @endif
                                </div>
                        @endswitch
                    </div>
                @endforeach
            </div>

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
