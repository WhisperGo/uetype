{{-- Clan War page: shows the current war state (incoming challenge, pending, ongoing,
     or open to challenge), the per-mode claim grid, and past-war history. --}}
<div class="py-10">
    <x-page-container>

    <div class="flex flex-wrap items-center justify-between gap-3 mb-2">
        <h1 class="font-display text-fluid-title tracking-wide text-foreground">{{ __('clan.war_title') }}</h1>
        <div class="flex items-center gap-4">
            <a href="{{ route('clan-leaderboard.index') }}" wire:navigate class="font-mono text-xs text-muted hover:text-foreground transition">
                {{ __('clan.leaderboard') }}
            </a>
            @if ($this->myClan)
                <a href="{{ route('clans.index') }}" wire:navigate class="font-mono text-xs text-muted hover:text-foreground transition">
                    {{ __('clan.back_to_clan') }}
                </a>
            @endif
        </div>
    </div>

    @if (! $this->myClan)
        <x-empty-state :title="__('clan.war.no_clan_title')" :body="__('clan.war.no_clan_body')">
            <x-slot:cta>
                <x-btn-gold as="a" size="lg" href="{{ route('clans.index') }}" wire:navigate>
                    {{ __('clan.war.go_to_clans') }}
                </x-btn-gold>
            </x-slot:cta>
        </x-empty-state>
    @else
        <p class="font-mono text-xs text-muted mb-6">
            {{ __('clan.war.clan_power', ['name' => $this->myClan->name, 'power' => number_format($this->myClan->power)]) }}
        </p>

        {{-- ================= INCOMING CHALLENGE (leader only) ================= --}}
        @if ($this->incomingChallenge)
            @php $war = $this->incomingChallenge; @endphp
            <div class="p-5 border bg-surface/40 border-gold/30 rounded-2xl mb-6">
                <p class="font-mono text-xs uppercase tracking-widest text-gold mb-3">{{ __('clan.war.incoming') }}</p>
                <div class="flex items-center gap-4">
                    <x-clan-emblem :clan="$war->challenger" size="md" />
                    <div class="min-w-0">
                        <p class="font-mono text-sm text-foreground">
                            <span class="font-bold">{{ $war->challenger->name }}</span>
                            {{ __('clan.war.incoming_suffix', ['power' => number_format($war->challenger->power)]) }}
                        </p>
                        <p class="font-mono text-xs text-muted mt-1">
                            {{ __('clan.war.respond_before', ['time' => \App\Support\AppTime::format($war->accept_deadline_at, 'd M Y H:i')]) }}
                        </p>
                    </div>
                </div>
                <div class="flex gap-3 mt-4">
                    <x-btn-gold wire:click="acceptChallenge({{ $war->id }})">{{ __('clan.war.accept') }}</x-btn-gold>
                    <button wire:click="declineChallenge({{ $war->id }})"
                        class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                        {{ __('clan.war.decline') }}
                    </button>
                </div>
            </div>

        {{-- ================= WAR CHALLENGE SENT (waiting for opponent to accept) ================= --}}
        @elseif ($this->myActiveWar && $this->myActiveWar->status->value === 'pending')
            @php $war = $this->myActiveWar; @endphp
            <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl mb-6">
                <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('clan.war.waiting') }}</p>
                <div class="flex items-center gap-4">
                    <x-clan-emblem :clan="$war->opponent" size="md" />
                    <div class="min-w-0">
                        <p class="font-mono text-sm text-foreground">
                            {{ __('clan.war.waiting_prefix') }} <span class="font-bold">{{ $war->opponent->name }}</span>
                            {{ __('clan.war.waiting_suffix', ['power' => number_format($war->opponent->power)]) }}
                        </p>
                        <p class="font-mono text-xs text-muted mt-1">
                            {{ __('clan.war.expires_at', ['time' => \App\Support\AppTime::format($war->accept_deadline_at, 'd M Y H:i')]) }}
                        </p>
                    </div>
                </div>
            </div>

        {{-- ================= WAR ONGOING ================= --}}
        @elseif ($this->myActiveWar && $this->myActiveWar->status->value === 'ongoing')
            @php
                $war = $this->myActiveWar;
                $opponent = $war->challenger_clan_id === $this->myClan->id ? $war->opponent : $war->challenger;
            @endphp
            <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl mb-6">
                <div class="flex items-center justify-between flex-wrap gap-4">
                    <div class="flex items-center gap-4">
                        <x-clan-emblem :clan="$opponent" size="md" />
                        <div>
                            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-2">{{ __('clan.war.ongoing') }}</p>
                            <p class="font-mono text-sm text-foreground">
                                {{ __('clan.war.vs_prefix') }} <span class="font-bold">{{ $opponent->name }}</span>
                                {{ __('clan.war.vs_suffix', ['power' => number_format($opponent->power)]) }}
                            </p>
                            <p class="font-mono text-xs text-muted mt-1">
                                {{ __('clan.war.ends_at', ['time' => \App\Support\AppTime::format($war->ends_at, 'd M Y H:i')]) }}
                            </p>
                        </div>
                    </div>
                    <div class="text-right">
                        <p class="font-mono text-xs uppercase tracking-widest text-muted">{{ __('clan.war.your_points') }}</p>
                        <p class="font-mono text-2xl sm:text-3xl font-bold text-gold tabular-nums">{{ rtrim(rtrim(number_format($this->myClanPoints, 1), '0'), '.') }}</p>
                    </div>
                </div>
            </div>

            @if (session('clan_war_claim_error'))
                <p class="font-mono text-xs text-danger mb-4">{{ session('clan_war_claim_error') }}</p>
            @endif

            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-1">{{ __('clan.war.modes_heading') }}</p>
            <p class="font-mono text-xs text-muted mb-3">{{ __('clan.war.modes_hint') }}</p>
            <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 gap-3 mb-8">
                @foreach ($this->modeGrid as $slot)
                    @php
                        $labelMode = __('clan.mode.'.$slot['mode']);
                        $labelConfig = $slot['mode'] === 'survival'
                            ? __('clan.mode.survival_'.$slot['config'])
                            : ($slot['mode'] === 'time'
                                ? __('clan.mode.config_time', ['n' => $slot['config']])
                                : __('clan.mode.config_words', ['n' => $slot['config']]));
                    @endphp
                    <div class="p-4 border rounded-2xl flex flex-col gap-3
                        {{ $slot['status'] === 'done' ? 'bg-gold/5 border-gold/30' : 'bg-surface/40 border-white/5' }}"
                        wire:key="slot-{{ $slot['mode'] }}-{{ $slot['config'] }}">
                        <div class="flex items-start justify-between gap-2">
                            <div class="min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate">{{ __('clan.war.mode_label', ['mode' => $labelMode, 'config' => $labelConfig]) }}</p>
                                <p class="font-mono text-[0.65rem] text-muted mt-0.5">{{ __('clan.war.ceiling', ['points' => $slot['ceiling']]) }}</p>
                            </div>
                            @if ($slot['status'] === 'done')
                                <span class="font-mono text-sm font-bold text-gold tabular-nums shrink-0">{{ rtrim(rtrim(number_format($slot['claim']->points, 1), '0'), '.') }}</span>
                            @endif
                        </div>

                        @switch($slot['status'])
                            @case('open')
                                <x-btn-gold size="px-3 py-2 text-xs" class="w-full"
                                    wire:click="claimMode('{{ $slot['mode'] }}', '{{ $slot['config'] }}')">
                                    {{ __('clan.war.claim_play') }}
                                </x-btn-gold>
                                @break

                            @case('claimed')
                                <div class="flex items-center justify-between gap-2">
                                    <span class="font-mono text-[0.7rem] text-muted truncate">{{ __('clan.war.claimed_by', ['name' => $slot['claim']->user->username]) }}</span>
                                    @if ($slot['claim']->user_id === auth()->id())
                                        <x-btn-gold as="a" size="xs" class="shrink-0" wire:navigate
                                            href="{{ route('typing', ['war_claim' => $slot['claim']->id]) }}">{{ __('clan.war.play') }}</x-btn-gold>
                                    @endif
                                </div>
                                @if ($slot['claim']->user_id === auth()->id() || $this->isLeader)
                                    <button type="button"
                                        @click="$dispatch('open-modal', { name: 'confirm-cancel-claim', id: {{ $slot['claim']->id }}, label: @js($labelMode.' '.$labelConfig) })"
                                        class="w-full px-3 py-1.5 font-mono text-[0.7rem] text-danger border border-danger/30 rounded-lg hover:bg-danger/10 transition">
                                        {{ __('clan.war.cancel_claim') }}
                                    </button>
                                @endif
                                @break

                            @default
                                {{-- Done: show who played it plus the ORIGINAL TYPING RESULT behind the points. --}}
                                @php $tr = $slot['claim']->typingResult; @endphp
                                <div class="flex flex-col gap-1.5">
                                    <span class="font-mono text-[0.7rem] text-muted truncate">✓ {{ $slot['claim']->user->username }}</span>
                                    @if ($tr)
                                        <div class="flex flex-wrap gap-x-3 gap-y-0.5 font-mono text-[0.65rem] text-muted border-t border-white/5 pt-1.5">
                                            @if ($slot['mode'] === 'survival')
                                                <span>{!! __('clan.war.stat_survival', ['value' => '<span class="text-foreground font-bold">'.rtrim(rtrim(number_format($tr->duration_seconds, 1), '0'), '.').'</span>']) !!}</span>
                                            @else
                                                <span>{!! __('clan.war.stat_wpm', ['value' => '<span class="text-foreground font-bold">'.rtrim(rtrim(number_format($tr->net_wpm, 1), '0'), '.').'</span>']) !!}</span>
                                            @endif
                                            <span>{!! __('clan.war.stat_accuracy', ['value' => '<span class="text-foreground font-bold">'.rtrim(rtrim(number_format($tr->accuracy, 1), '0'), '.').'</span>']) !!}</span>
                                        </div>
                                    @endif
                                </div>
                        @endswitch
                    </div>
                @endforeach
            </div>

        {{-- ================= FREE: CAN CHALLENGE (leader only) ================= --}}
        @elseif ($this->isLeader)
            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('clan.war.challenge_heading') }}</p>
            @if ($this->challengeableClans->count() > 0)
                <div class="space-y-3 mb-8">
                    @foreach ($this->challengeableClans as $clan)
                        <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl" wire:key="challenge-{{ $clan->id }}">
                            <x-clan-emblem :clan="$clan" size="sm" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate">
                                    {{ $clan->name }}
                                    @if ($clan->tag)
                                        <span class="text-muted font-normal">[{{ $clan->tag }}]</span>
                                    @endif
                                </p>
                                <p class="font-mono text-xs text-muted mt-0.5">{{ __('clan.power_inline', ['value' => number_format($clan->power)]) }}</p>
                            </div>
                            <x-btn-gold wire:click="challengeClan({{ $clan->id }})">{{ __('clan.war.challenge') }}</x-btn-gold>
                        </div>
                    @endforeach
                </div>
            @else
                <p class="font-mono text-sm text-muted py-6 mb-8">{{ __('clan.war.challengeable_empty') }}</p>
            @endif
        @else
            <p class="font-mono text-sm text-muted py-6 mb-8">{{ __('clan.war.not_in_war') }}</p>
        @endif

        {{-- ================= WAR HISTORY ================= --}}
        @if ($this->warHistory->count() > 0)
            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('clan.war.history_heading') }}</p>
            <div class="space-y-3">
                @foreach ($this->warHistory as $war)
                    @php
                        $isChallenger = $war->challenger_clan_id === $this->myClan->id;
                        $opponent = $isChallenger ? $war->opponent : $war->challenger;
                        $myDelta = $isChallenger ? $war->challenger_power_delta : $war->opponent_power_delta;

                        // 'result' is stored from the challenger's point of view; flip it if we're the opponent.
                        if ($war->result === 'draw') {
                            $myResult = 'draw';
                        } elseif ($isChallenger) {
                            $myResult = $war->result; // 'win' or 'loss' as-is
                        } else {
                            $myResult = $war->result === 'win' ? 'loss' : 'win';
                        }
                    @endphp
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl" wire:key="war-{{ $war->id }}">
                        <span class="font-mono text-xs font-bold uppercase w-12 shrink-0
                            {{ $myResult === 'win' ? 'text-gold' : ($myResult === 'draw' ? 'text-muted' : 'text-danger') }}">
                            {{ __('clan.result.'.$myResult) }}
                        </span>
                        <x-clan-emblem :clan="$opponent" size="sm" />
                        <div class="flex-1 min-w-0">
                            <p class="font-mono text-sm text-foreground truncate">{{ __('clan.war.history_vs', ['name' => $opponent->name]) }}</p>
                        </div>
                        <span class="font-mono text-sm font-bold tabular-nums {{ $myDelta >= 0 ? 'text-gold' : 'text-danger' }}">
                            {{ $myDelta >= 0 ? '+' : '' }}{{ $myDelta }}
                        </span>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ===== CONFIRMATION MODAL (themed, replaces wire:confirm / direct action) ===== --}}
    @if ($this->myClan)
        <div x-data="{ claimId: null, claimLabel: '' }"
            @open-modal.window="if ($event.detail?.name === 'confirm-cancel-claim') { claimId = $event.detail.id; claimLabel = $event.detail.label; $dispatch('open-modal', 'confirm-cancel-claim') }">
            <x-modal name="confirm-cancel-claim" maxWidth="md">
                <div class="p-6">
                    <p class="font-mono text-sm font-bold text-foreground">{{ __('clan.modal.cancel_claim_title') }}</p>
                    <p class="font-mono text-xs text-gold mt-1" x-text="claimLabel"></p>
                    <p class="font-mono text-xs text-muted mt-2">{{ __('clan.modal.cancel_claim_body') }}</p>
                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" @click="$dispatch('close-modal', 'confirm-cancel-claim')"
                            class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                            {{ __('clan.modal.cancel') }}
                        </button>
                        <button type="button" @click="$wire.cancelClaim(claimId); $dispatch('close-modal', 'confirm-cancel-claim')"
                            class="px-4 py-2 font-mono text-xs font-bold text-foreground bg-danger hover:bg-danger/80 rounded-lg transition">
                            {{ __('clan.modal.cancel_claim_confirm') }}
                        </button>
                    </div>
                </div>
            </x-modal>
        </div>
    @endif

    {{-- ===== REAL-TIME (reuses the global toast subscriber in the layout) ===== --}}
    @script
        <script>
            const onRemote = () => $wire.dispatch('clan-updated');
            window.addEventListener('clan-updated-remote', onRemote);

            document.addEventListener('livewire:navigating', () => {
                window.removeEventListener('clan-updated-remote', onRemote);
            }, { once: true });
        </script>
    @endscript
    </x-page-container>
</div>
