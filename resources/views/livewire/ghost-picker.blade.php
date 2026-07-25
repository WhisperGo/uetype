{{-- Ghost-opponent picker modal: choose a WPM pace to race against (your best, a
     friend, or a leaderboard entry). Only available for the time and words modes. --}}
<div>
    <x-modal name="ghost-picker" maxWidth="md" focusable>
        <div class="p-6 space-y-5">
            <div class="flex items-center justify-between">
                <h3 class="font-mono text-lg font-bold text-foreground">{{ __('ghost.title') }}</h3>
                <button type="button" x-on:click="$dispatch('close-modal', 'ghost-picker')"
                    class="text-muted hover:text-foreground transition-colors" aria-label="{{ __('ghost.close') }}">
                    <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>

            @if (! in_array($mainMode, ['time', 'words'], true))
                <p class="font-mono text-sm text-muted">{{ __('ghost.only_time_words') }}</p>
            @else
                {{-- ===== MY BEST ===== --}}
                <div>
                    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-2">{{ __('ghost.my_best') }}</p>
                    @if ($this->myBest > 0)
                        <button type="button" wire:click="selectOpponent('own')"
                            class="w-full flex items-center justify-between p-3 border bg-surface/40 border-white/5 rounded-xl hover:border-brand/40 transition text-left">
                            <span class="font-mono text-sm text-foreground">{{ __('ghost.your_best') }}</span>
                            <span class="font-mono text-sm font-bold text-brand-bright">{{ rtrim(rtrim(number_format($this->myBest, 1), '0'), '.') }} wpm</span>
                        </button>
                    @else
                        <p class="font-mono text-xs text-muted italic">{{ __('ghost.my_best_empty') }}</p>
                    @endif
                </div>

                {{-- ===== FRIENDS ===== --}}
                <div>
                    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-2">{{ __('ghost.friends') }}</p>
                    @if ($this->eligibleFriends->count() > 0)
                        <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                            @foreach ($this->eligibleFriends as $row)
                                <button type="button" wire:click="selectOpponent('friend', {{ $row['friendship_id'] }})"
                                    wire:key="ghost-friend-{{ $row['friendship_id'] }}"
                                    class="w-full flex items-center justify-between p-3 border bg-surface/40 border-white/5 rounded-xl hover:border-brand/40 transition text-left">
                                    <span class="font-mono text-sm text-foreground truncate">{{ $row['username'] }}</span>
                                    <span class="font-mono text-sm font-bold text-brand-bright shrink-0 ml-2">{{ rtrim(rtrim(number_format($row['wpm'], 1), '0'), '.') }} wpm</span>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <p class="font-mono text-xs text-muted italic">{{ __('ghost.friends_empty') }}</p>
                    @endif
                </div>

                {{-- ===== LEADERBOARD ===== --}}
                <div>
                    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-2">
                        {{ __('ghost.leaderboard') }} &middot; {{ ucfirst($mainMode) }} {{ $subMode }}
                    </p>
                    @if ($this->eligibleLeaderboard->count() > 0)
                        <div class="space-y-2 max-h-48 overflow-y-auto pr-1">
                            @foreach ($this->eligibleLeaderboard as $row)
                                <button type="button" wire:click="selectOpponent('leaderboard', {{ $row['user_id'] }})"
                                    wire:key="ghost-lb-{{ $row['user_id'] }}"
                                    class="w-full flex items-center justify-between p-3 border bg-surface/40 border-white/5 rounded-xl hover:border-gold/40 transition text-left">
                                    <span class="font-mono text-sm text-foreground truncate">{{ $row['username'] }}</span>
                                    <span class="font-mono text-sm font-bold text-gold shrink-0 ml-2">{{ rtrim(rtrim(number_format($row['wpm'], 1), '0'), '.') }} wpm</span>
                                </button>
                            @endforeach
                        </div>
                    @else
                        <p class="font-mono text-xs text-muted italic">{{ __('ghost.leaderboard_empty') }}</p>
                    @endif
                </div>

                <button type="button" wire:click="clearOpponent"
                    x-on:click="$dispatch('close-modal', 'ghost-picker')"
                    class="w-full text-center font-mono text-xs text-muted hover:text-foreground transition py-2">
                    {{ __('ghost.race_without') }}
                </button>
            @endif
        </div>
    </x-modal>
</div>
