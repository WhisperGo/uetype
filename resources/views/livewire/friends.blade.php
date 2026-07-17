<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <h1 class="font-display text-fluid-title tracking-wide text-foreground mb-4">{{ __('friends.title') }}</h1>

    <!-- ===== TABS ===== -->
    <div class="border-b border-white/10 mb-6">
        <nav class="flex gap-6 -mb-px font-mono text-sm" aria-label="{{ __('friends.tab.aria') }}">
            <button wire:click="setTab('friends')"
                @class([
                    'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                    'border-gold text-foreground font-semibold' => $tab === 'friends',
                    'border-transparent text-muted hover:text-foreground' => $tab !== 'friends',
                ])>
                {{ __('friends.tab.friends', ['count' => $this->friendsList->count()]) }}
            </button>
            <button wire:click="setTab('requests')"
                @class([
                    'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                    'border-gold text-foreground font-semibold' => $tab === 'requests',
                    'border-transparent text-muted hover:text-foreground' => $tab !== 'requests',
                ])>
                {{ __('friends.tab.requests', ['count' => $this->requestsCount]) }}
            </button>
            <button wire:click="setTab('find')"
                @class([
                    'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                    'border-gold text-foreground font-semibold' => $tab === 'find',
                    'border-transparent text-muted hover:text-foreground' => $tab !== 'find',
                ])>
                {{ __('friends.tab.find') }}
            </button>
        </nav>
    </div>

    {{-- ===================================================================== --}}
    {{-- TAB: FRIENDS LIST --}}
    {{-- ===================================================================== --}}
    @if ($tab === 'friends')
        @if ($this->friendsList->count() > 0)
            <div class="space-y-3">
                @foreach ($this->friendsList as $row)
                    @php $friendWpm = (float) ($row['user']->highest_wpm ?? 0); @endphp
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl hover:border-white/10 transition group" wire:key="friend-{{ $row['friendship_id'] }}">
                        <a href="{{ route('profile.show', $row['user']) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                            <x-friend-avatar :user="$row['user']" :online="$row['online']" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $row['user']->username }}</p>
                                @if ($row['online'])
                                    <p class="font-mono text-xs mt-0.5 flex items-center gap-1.5 text-active">
                                        <span class="w-1.5 h-1.5 rounded-full bg-active"></span>{{ __('friends.online') }}
                                    </p>
                                @elseif ($row['user']->last_seen_at)
                                    <p class="font-mono text-xs text-muted mt-0.5">{{ __('friends.last_seen', ['time' => $row['user']->last_seen_at->diffForHumans()]) }}</p>
                                @else
                                    <p class="font-mono text-xs text-muted mt-0.5">{{ __('friends.level', ['level' => $row['user']->levelData()['level']]) }}</p>
                                @endif
                            </div>
                        </a>

                        <div class="shrink-0 hidden sm:flex flex-col items-end font-mono leading-tight">
                            @if ($friendWpm > 0)
                                <span class="text-sm font-bold text-gold tabular-nums">{{ __('friends.wpm_short', ['wpm' => rtrim(rtrim(number_format($friendWpm, 1), '0'), '.')]) }}</span>
                            @endif
                            <span class="text-[0.65rem] uppercase tracking-wider text-muted">{{ __('friends.level', ['level' => $row['user']->levelData()['level']]) }}</span>
                        </div>

                        <a href="{{ route('chat.index', ['mode' => 'dm', 'with' => $row['user']->username]) }}" wire:navigate
                            class="px-3 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition shrink-0">
                            {{ __('friends.chat') }}
                        </a>

                        <div class="shrink-0 relative" x-data="{ open: false, ghost: false }" @click.outside="open = false; ghost = false" @keydown.escape="open = false; ghost = false">
                            <button type="button" @click="open = !open; ghost = false"
                                aria-label="{{ __('friends.actions') }}"
                                class="p-1.5 rounded-lg text-muted hover:text-foreground hover:bg-white/5 transition">
                                <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 5v.01M12 12v.01M12 19v.01" />
                                </svg>
                            </button>

                            <div x-show="open" x-cloak
                                x-transition:enter="transition ease-out duration-150"
                                x-transition:enter-start="opacity-0 scale-95"
                                x-transition:enter-end="opacity-100 scale-100"
                                x-transition:leave="transition ease-in duration-100"
                                x-transition:leave-start="opacity-100 scale-100"
                                x-transition:leave-end="opacity-0 scale-95"
                                class="absolute right-0 top-full z-50 mt-1 w-60 origin-top-right rounded-xl border border-white/10 bg-surface shadow-lg ring-1 ring-black/20 overflow-hidden py-1">

                                <a href="{{ route('profile.show', $row['user']) }}" wire:navigate
                                    class="block px-4 py-2.5 text-xs font-bold text-foreground whitespace-nowrap hover:bg-white/5 transition">
                                    {{ __('friends.menu_view_profile') }}
                                </a>

                                @php
                                    $ghostConfigs = $row['ghost_configs'] ?? [];
                                    $ghostTime = $ghostConfigs['time'] ?? [];
                                    $ghostWords = $ghostConfigs['words'] ?? [];
                                    $hasGhost = ! empty($ghostTime) || ! empty($ghostWords);
                                @endphp
                                @if ($hasGhost)
                                    <button type="button" @click="ghost = !ghost"
                                        class="w-full flex items-center justify-between px-4 py-2.5 text-xs font-bold text-foreground whitespace-nowrap hover:bg-white/5 transition text-left">
                                        <span>{{ __('friends.race_ghost') }}</span>
                                        <span class="text-muted tabular-nums" x-text="ghost ? '-' : '+'"></span>
                                    </button>

                                    <div x-show="ghost" x-cloak class="border-t border-white/5 bg-background/40 px-3 py-2 space-y-2">
                                        @if (! empty($ghostTime))
                                            <div>
                                                <p class="text-[0.6rem] uppercase tracking-widest text-muted mb-1.5">{{ __('friends.race_ghost_time') }}</p>
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach ($ghostTime as $t)
                                                        <a href="{{ route('typing') }}?ghost={{ $row['user']->id }}&mode=time&config={{ $t }}" wire:navigate
                                                            class="px-2 py-1 rounded-md text-[0.7rem] font-bold tabular-nums text-muted hover:text-foreground hover:bg-white/5 border border-white/10 transition">{{ $t }}</a>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                        @if (! empty($ghostWords))
                                            <div>
                                                <p class="text-[0.6rem] uppercase tracking-widest text-muted mb-1.5">{{ __('friends.race_ghost_words') }}</p>
                                                <div class="flex flex-wrap gap-1">
                                                    @foreach ($ghostWords as $w)
                                                        <a href="{{ route('typing') }}?ghost={{ $row['user']->id }}&mode=words&config={{ $w }}" wire:navigate
                                                            class="px-2 py-1 rounded-md text-[0.7rem] font-bold tabular-nums text-muted hover:text-foreground hover:bg-white/5 border border-white/10 transition">{{ $w }}</a>
                                                    @endforeach
                                                </div>
                                            </div>
                                        @endif
                                    </div>
                                @else
                                    <div class="flex items-center justify-between gap-4 px-4 py-2.5 text-xs font-bold text-muted whitespace-nowrap cursor-default">
                                        <span>{{ __('friends.race_ghost') }}</span>
                                        <span class="text-[0.65rem] font-medium normal-case text-muted/70">{{ __('friends.race_ghost_none') }}</span>
                                    </div>
                                @endif

                                <button type="button" wire:click="removeFriend({{ $row['friendship_id'] }})"
                                    wire:confirm="{{ __('friends.confirm_remove', ['name' => $row['user']->username]) }}"
                                    @click="open = false"
                                    class="block w-full px-4 py-2.5 text-xs font-bold text-danger whitespace-nowrap hover:bg-danger/10 transition text-left border-t border-white/5">
                                    {{ __('friends.menu_remove') }}
                                </button>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex flex-col items-center justify-center py-24 text-center select-none">
                <img src="/icon/uetype_mascot.png" alt="" class="w-16 opacity-30 mb-4">
                <p class="font-mono text-sm font-bold text-foreground">{{ __('friends.empty_friends_title') }}</p>
                <p class="font-mono text-xs text-muted mt-1">{{ __('friends.empty_friends_body') }}</p>
                <button wire:click="setTab('find')"
                    class="mt-5 px-5 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                    {{ __('friends.find_friends') }}
                </button>
            </div>
        @endif
    @endif

    {{-- ===================================================================== --}}
    {{-- TAB: REQUESTS (incoming + sent) --}}
    {{-- ===================================================================== --}}
    @if ($tab === 'requests')
        @if ($this->incomingRequests->count() === 0 && $this->sentRequests->count() === 0)
            <div class="flex flex-col items-center justify-center py-24 text-center select-none">
                <img src="/icon/uetype_mascot.png" alt="" class="w-16 opacity-30 mb-4">
                <p class="font-mono text-sm font-bold text-foreground">{{ __('friends.empty_requests_title') }}</p>
                <p class="font-mono text-xs text-muted mt-1">{{ __('friends.empty_requests_body') }}</p>
            </div>
        @else
            {{-- INCOMING --}}
            @if ($this->incomingRequests->count() > 0)
                <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('friends.incoming', ['count' => $this->incomingRequests->count()]) }}</p>
                <div class="space-y-3 mb-8">
                    @foreach ($this->incomingRequests as $req)
                        <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group" wire:key="in-{{ $req->id }}">
                            <a href="{{ route('profile.show', $req->requester) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                                <x-friend-avatar :user="$req->requester" />
                                <div class="flex-1 min-w-0">
                                    <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $req->requester->username }}</p>
                                    <p class="font-mono text-xs text-muted mt-0.5">{{ __('friends.level', ['level' => $req->requester->levelData()['level']]) }}</p>
                                </div>
                            </a>
                            <button wire:click="acceptRequest({{ $req->id }})"
                                class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                                {{ __('friends.accept') }}
                            </button>
                            <button wire:click="rejectRequest({{ $req->id }})"
                                class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                                {{ __('friends.reject') }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- SENT --}}
            @if ($this->sentRequests->count() > 0)
                <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('friends.sent', ['count' => $this->sentRequests->count()]) }}</p>
                <div class="space-y-3">
                    @foreach ($this->sentRequests as $req)
                        <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group" wire:key="sent-{{ $req->id }}">
                            <a href="{{ route('profile.show', $req->addressee) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                                <x-friend-avatar :user="$req->addressee" />
                                <div class="flex-1 min-w-0">
                                    <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $req->addressee->username }}</p>
                                    <p class="font-mono text-xs text-muted mt-0.5">{{ __('friends.level', ['level' => $req->addressee->levelData()['level']]) }}</p>
                                </div>
                            </a>
                            <span class="font-mono text-xs text-muted">{{ __('friends.pending') }}</span>
                            <button wire:click="cancelRequest({{ $req->id }})"
                                class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                                {{ __('friends.cancel') }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif
        @endif
    @endif

    {{-- ===================================================================== --}}
    {{-- TAB: FIND FRIENDS (search) --}}
    {{-- ===================================================================== --}}
    @if ($tab === 'find')
        <div class="relative mb-6">
            <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
            </svg>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('friends.search_placeholder') }}"
                class="w-full pl-11 pr-4 py-3.5 bg-surface/40 border border-white/10 rounded-2xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
        </div>

        @if (strlen(trim($search)) > 0)
            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('friends.results') }}</p>
            @if ($this->searchResults->count() > 0)
                <div class="space-y-3">
                    @foreach ($this->searchResults as $row)
                        <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group" wire:key="find-{{ $row['user']->id }}">
                            <a href="{{ route('profile.show', $row['user']) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                                <x-friend-avatar :user="$row['user']" />
                                <div class="flex-1 min-w-0">
                                    <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $row['user']->username }}</p>
                                    <p class="font-mono text-xs text-muted mt-0.5">{{ __('friends.level', ['level' => $row['user']->levelData()['level']]) }}</p>
                                </div>
                            </a>

                            @switch($row['relation'])
                                @case('friends')
                                    <span class="px-4 py-1.5 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg flex items-center gap-1.5">
                                        <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        {{ __('friends.relation_friends') }}
                                    </span>
                                    @break
                                @case('sent')
                                    <span class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg">{{ __('friends.request_sent') }}</span>
                                    @break
                                @case('incoming')
                                    <button wire:click="setTab('requests')"
                                        class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                                        {{ __('friends.respond') }}
                                    </button>
                                    @break
                                @default
                                    <button wire:click="sendRequest({{ $row['user']->id }})"
                                        class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition flex items-center gap-1">
                                        {{ __('friends.add') }}
                                    </button>
                            @endswitch
                        </div>
                    @endforeach
                </div>
            @else
                <p class="font-mono text-sm text-muted py-6">{{ __('friends.no_results', ['query' => trim($search)]) }}</p>
            @endif
        @else
            <div class="flex flex-col items-center justify-center py-20 text-center select-none">
                <p class="font-mono text-xs text-muted">{{ __('friends.find_prompt') }}</p>
            </div>
        @endif
    @endif

    {{-- ===== REAL-TIME =====
         Subscription Echo ke friends.{id} DIPEGANG oleh toast global di layout
         (satu-satunya subscriber, agar tak dobel). Halaman ini cukup mendengar
         event window 'friendship-updated-remote' yang diteruskan toast lalu
         menyegarkan datanya. --}}
    @script
        <script>
            const onRemote = () => $wire.dispatch('friendship-updated');
            window.addEventListener('friendship-updated-remote', onRemote);

            // Lepas listener saat komponen dibongkar (hindari penumpukan lintas navigate).
            document.addEventListener('livewire:navigating', () => {
                window.removeEventListener('friendship-updated-remote', onRemote);
            }, { once: true });
        </script>
    @endscript
</div>
