<div class="py-10">
    <x-page-container>

    <h1 class="font-display text-fluid-title tracking-wide text-foreground mb-4">CLAN</h1>

    <!-- ===== TABS ===== -->
    <div class="border-b border-white/10 mb-6">
        <nav class="flex gap-6 -mb-px font-mono text-sm" aria-label="Clan tabs">
            <button wire:click="setTab('my-clan')"
                @class([
                    'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                    'border-gold text-foreground font-semibold' => $tab === 'my-clan',
                    'border-transparent text-muted hover:text-foreground' => $tab !== 'my-clan',
                ])>
                My Clan
                @if ($this->pendingRequests->count() > 0)
                    <span class="ml-1 px-1.5 py-0.5 rounded bg-gold/20 text-gold text-[0.65rem]">{{ $this->pendingRequests->count() }}</span>
                @endif
            </button>
            @unless ($this->myClan)
                <button wire:click="setTab('browse')"
                    @class([
                        'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                        'border-gold text-foreground font-semibold' => $tab === 'browse',
                        'border-transparent text-muted hover:text-foreground' => $tab !== 'browse',
                    ])>
                    Browse Clans
                </button>
                <button wire:click="setTab('create')"
                    @class([
                        'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                        'border-gold text-foreground font-semibold' => $tab === 'create',
                        'border-transparent text-muted hover:text-foreground' => $tab !== 'create',
                    ])>
                    Create Clan
                </button>
            @endunless
        </nav>
    </div>

    {{-- TAB: MY CLAN --}}
    @if ($tab === 'my-clan')
        @if ($this->myClan)
            @php $lvl = $this->myClan->levelData(); @endphp
            {{-- HERO CARD --}}
            <div class="relative overflow-hidden p-5 sm:p-6 border bg-surface/70 border-white/10 rounded-3xl mb-6">
                <div class="pointer-events-none absolute -top-16 -right-16 w-56 h-56 rounded-full bg-gold/5 blur-2xl"></div>
                <div class="relative flex flex-col sm:flex-row sm:items-center gap-5">
                    <x-clan-emblem :clan="$this->myClan" size="lg" />

                    <div class="flex-1 min-w-0">
                        <p class="font-mono text-xl font-bold text-foreground leading-tight">
                            {{ $this->myClan->name }}
                            @if ($this->myClan->tag)<span class="text-muted font-normal">[{{ $this->myClan->tag }}]</span>@endif
                        </p>
                        @if ($this->myClan->description)
                            <p class="font-mono text-xs text-muted mt-1 max-w-md">{{ $this->myClan->description }}</p>
                        @endif
                        <div class="flex flex-wrap items-center gap-2 mt-2.5">
                            <span class="px-2.5 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg">Lv {{ $lvl['level'] }}</span>
                            <span class="font-mono text-xs text-muted">
                                {{ $this->myClanMembers->count() }} / {{ \App\Livewire\Clans::MAX_MEMBERS }} members
                                · power <span class="text-gold font-bold">{{ number_format($this->myClan->power) }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <a href="{{ route('clan-war.index') }}" wire:navigate
                            class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                            Clan War
                        </a>
                        <a href="{{ route('clan-leaderboard.index') }}" wire:navigate
                            class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                            Leaderboard
                        </a>
                        @if ($this->myMembership->role->value !== 'leader')
                            <button wire:click="leaveClan"
                                wire:confirm="Keluar dari {{ $this->myClan->name }}?"
                                class="px-4 py-1.5 font-mono text-xs text-red-400/80 border border-red-900/40 rounded-lg hover:bg-red-950/30 transition">
                                Leave Clan
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Bar progres level --}}
                <div class="relative mt-5">
                    <div class="flex justify-between font-mono text-[0.6rem] uppercase tracking-wider text-muted mb-1.5">
                        <span>Lv {{ $lvl['level'] }}</span>
                        <span>{{ $lvl['progress'] }} / {{ $lvl['needed'] }} power</span>
                        <span>Lv {{ $lvl['next_level'] }}</span>
                    </div>
                    <div class="h-2 w-full rounded-full bg-white/5 overflow-hidden">
                        <div class="h-full rounded-full bg-gold transition-all" style="width: {{ $lvl['needed'] > 0 ? min(100, round($lvl['progress'] / $lvl['needed'] * 100)) : 0 }}%"></div>
                    </div>
                </div>
            </div>

            {{-- Permintaan gabung masuk (khusus leader) --}}
            @if ($this->myMembership->role->value === 'leader' && $this->pendingRequests->count() > 0)
                <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Join Requests ({{ $this->pendingRequests->count() }})</p>
                <div class="space-y-3 mb-8">
                    @foreach ($this->pendingRequests as $req)
                        <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl" wire:key="req-{{ $req->id }}">
                            <x-friend-avatar :user="$req->user" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate">{{ $req->user->username }}</p>
                                <p class="font-mono text-xs text-muted mt-0.5">level {{ $req->user->levelData()['level'] }}</p>
                            </div>
                            <button wire:click="approveMember({{ $req->id }})"
                                class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                                Accept
                            </button>
                            <button wire:click="rejectMember({{ $req->id }})"
                                class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                                Reject
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            @error('newName')
                <p class="font-mono text-xs text-red-400 mb-4">{{ $message }}</p>
            @enderror

            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">Members ({{ $this->myClanMembers->count() }})</p>
            <div class="space-y-3">
                @foreach ($this->myClanMembers as $member)
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group hover:border-white/10 transition {{ $member->role->value === 'leader' ? 'ring-1 ring-gold/20' : '' }}" wire:key="member-{{ $member->id }}">
                        <a href="{{ route('profile.show', $member->user) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                            <x-friend-avatar :user="$member->user" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $member->user->username }}</p>
                                <p class="font-mono text-xs text-muted mt-0.5">level {{ $member->user->levelData()['level'] }}</p>
                            </div>
                        </a>
                        @if ($member->role->value === 'leader')
                            <span class="px-3 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg inline-flex items-center gap-1.5 shrink-0">
                                <svg class="w-3 h-3" fill="currentColor" viewBox="0 0 24 24"><path d="M4 8l3.5 3L12 5l4.5 6L20 8l-1.5 10h-13L4 8z" /></svg>
                                Leader
                            </span>
                        @elseif ($this->myMembership->role->value === 'leader')
                            <button wire:click="kickMember({{ $member->id }})"
                                wire:confirm="Keluarkan {{ $member->user->username }} dari clan?"
                                class="opacity-0 group-hover:opacity-100 px-3 py-1.5 font-mono text-xs text-red-400/80 border border-red-900/40 rounded-lg hover:bg-red-950/30 transition">
                                Kick
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <div class="flex flex-col items-center justify-center py-24 text-center select-none">
                <img src="/icon/uetype_mascot.png" alt="" class="w-16 h-16 opacity-30 mb-4">
                <p class="font-mono text-sm font-bold text-foreground">You're not in a clan yet</p>
                <p class="font-mono text-xs text-muted mt-1">Browse existing clans or create your own</p>
                <div class="flex flex-wrap items-center justify-center gap-3 mt-5">
                    <button wire:click="setTab('browse')"
                        class="px-5 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                        Browse Clans
                    </button>
                    <button wire:click="setTab('create')"
                        class="px-5 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                        Create Clan
                    </button>
                    <a href="{{ route('clan-leaderboard.index') }}" wire:navigate
                        class="px-5 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                        Leaderboard
                    </a>
                </div>
            </div>
        @endif
    @endif

    {{-- TAB: BROWSE CLANS --}}
    @if ($tab === 'browse')
        <div class="relative mb-6">
            <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
            </svg>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="Search by clan name…"
                class="w-full pl-11 pr-4 py-3.5 bg-surface/40 border border-white/10 rounded-2xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
        </div>

        @if ($this->browseClans->count() > 0)
            <div class="space-y-3">
                @foreach ($this->browseClans as $row)
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group hover:border-white/10 transition" wire:key="clan-{{ $row['clan']->id }}">
                        <a href="{{ route('clans.show', $row['clan']) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                            <x-clan-emblem :clan="$row['clan']" size="sm" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">
                                    {{ $row['clan']->name }}
                                    @if ($row['clan']->tag)<span class="text-muted font-normal">[{{ $row['clan']->tag }}]</span>@endif
                                </p>
                                <p class="font-mono text-xs text-muted mt-0.5">
                                    Lv {{ $row['clan']->levelData()['level'] }}
                                    · {{ $row['clan']->members_count }} / {{ \App\Livewire\Clans::MAX_MEMBERS }} members
                                    · power {{ number_format($row['clan']->power) }}
                                </p>
                            </div>
                        </a>

                        @switch($row['relation'])
                            @case('member')
                                <span class="px-4 py-1.5 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg shrink-0">Joined</span>
                                @break
                            @case('pending')
                                <span class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg shrink-0">Request Sent</span>
                                @break
                            @default
                                <button wire:click="sendJoinRequest({{ $row['clan']->id }})"
                                    class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition shrink-0">
                                    + Join
                                </button>
                        @endswitch
                    </div>
                @endforeach
            </div>
        @else
            <p class="font-mono text-sm text-muted py-6">No clans found{{ trim($search) !== '' ? ' matching "'.trim($search).'"' : '' }}.</p>
        @endif
    @endif

    {{-- TAB: CREATE CLAN --}}
    @if ($tab === 'create')
        <form wire:submit.prevent="createClan" class="max-w-lg space-y-5">
            {{-- Preview + identitas --}}
            <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl">
                <x-clan-emblem :clan="(object) ['name' => $newName ?: '?', 'emblem' => $newEmblem, 'emblem_color' => $newEmblemColor]" size="lg" />
                <div class="min-w-0">
                    <p class="font-mono text-sm font-bold text-foreground truncate">{{ $newName ?: 'Clan name' }}
                        @if (trim($newTag) !== '')<span class="text-muted font-normal">[{{ $newTag }}]</span>@endif
                    </p>
                    <p class="font-mono text-xs text-muted mt-0.5 truncate">{{ $newDescription ?: 'Your clan preview' }}</p>
                </div>
            </div>

            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">Clan Name</label>
                <input type="text" wire:model.live="newName" maxlength="40"
                    placeholder="e.g. Speed Demons"
                    class="w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
                @error('newName')<p class="font-mono text-xs text-red-400 mt-1.5">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">Tag (optional)</label>
                <input type="text" wire:model.live="newTag" maxlength="6"
                    placeholder="e.g. SPD"
                    class="w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
                @error('newTag')<p class="font-mono text-xs text-red-400 mt-1.5">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">Description (optional)</label>
                <textarea wire:model.live="newDescription" maxlength="160" rows="2"
                    placeholder="What is your clan about?"
                    class="w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition resize-none"></textarea>
                @error('newDescription')<p class="font-mono text-xs text-red-400 mt-1.5">{{ $message }}</p>@enderror
            </div>

            {{-- Pemilih emblem --}}
            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">Emblem</label>
                <div class="mt-2 grid grid-cols-8 gap-2">
                    @foreach (\App\Support\ClanEmblem::icons() as $key => $path)
                        <button type="button" wire:click="$set('newEmblem', '{{ $key }}')"
                            aria-label="{{ $key }}"
                            @class([
                                'aspect-square flex items-center justify-center rounded-lg border transition',
                                'border-gold bg-gold/15 text-gold' => $newEmblem === $key,
                                'border-white/10 text-muted hover:text-foreground hover:border-white/20' => $newEmblem !== $key,
                            ])>
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}" /></svg>
                        </button>
                    @endforeach
                </div>
                @error('newEmblem')<p class="font-mono text-xs text-red-400 mt-1.5">{{ $message }}</p>@enderror
            </div>

            {{-- Pemilih warna --}}
            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">Accent Color</label>
                <div class="mt-2 flex flex-wrap gap-2.5">
                    @foreach (\App\Support\ClanEmblem::colors() as $key => $hex)
                        <button type="button" wire:click="$set('newEmblemColor', '{{ $key }}')"
                            aria-label="{{ $key }}"
                            class="w-8 h-8 rounded-full border-2 transition {{ $newEmblemColor === $key ? 'ring-2 ring-offset-2 ring-offset-background ring-white/60 border-white/60' : 'border-white/10 hover:border-white/30' }}"
                            style="background-color: {{ $hex }};"></button>
                    @endforeach
                </div>
                @error('newEmblemColor')<p class="font-mono text-xs text-red-400 mt-1.5">{{ $message }}</p>@enderror
            </div>

            <button type="submit"
                class="px-5 py-2.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                Create Clan
            </button>
        </form>
    @endif

    {{-- ===== REAL-TIME ===== --}}
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
