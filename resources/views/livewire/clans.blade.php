<div class="max-w-5xl px-4 mx-auto py-10 sm:px-6 lg:px-8">

    <h1 class="font-display text-2xl tracking-wide text-foreground mb-4">CLAN</h1>

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

    {{-- ===================================================================== --}}
    {{-- TAB: MY CLAN --}}
    {{-- ===================================================================== --}}
    @if ($tab === 'my-clan')
        @if ($this->myClan)
            <div class="p-5 border bg-surface/40 border-white/5 rounded-2xl mb-6">
                <div class="flex items-center justify-between flex-wrap gap-3">
                    <div>
                        <p class="font-mono text-lg font-bold text-foreground">
                            {{ $this->myClan->name }}
                            @if ($this->myClan->tag)
                                <span class="text-muted font-normal">[{{ $this->myClan->tag }}]</span>
                            @endif
                        </p>
                        <p class="font-mono text-xs text-muted mt-0.5">{{ $this->myClanMembers->count() }} / {{ \App\Livewire\Clans::MAX_MEMBERS }} members</p>
                    </div>
                    @if ($this->myMembership->role->value !== 'leader')
                        <button wire:click="leaveClan"
                            wire:confirm="Keluar dari {{ $this->myClan->name }}?"
                            class="px-4 py-1.5 font-mono text-xs text-red-400/80 border border-red-900/40 rounded-lg hover:bg-red-950/30 transition">
                            Leave Clan
                        </button>
                    @endif
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
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group" wire:key="member-{{ $member->id }}">
                        <a href="{{ route('profile.show', $member->user) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                            <x-friend-avatar :user="$member->user" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $member->user->username }}</p>
                                <p class="font-mono text-xs text-muted mt-0.5">level {{ $member->user->levelData()['level'] }}</p>
                            </div>
                        </a>
                        @if ($member->role->value === 'leader')
                            <span class="px-3 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg">Leader</span>
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
                <div class="flex gap-3 mt-5">
                    <button wire:click="setTab('browse')"
                        class="px-5 py-2 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                        Browse Clans
                    </button>
                    <button wire:click="setTab('create')"
                        class="px-5 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                        Create Clan
                    </button>
                </div>
            </div>
        @endif
    @endif

    {{-- ===================================================================== --}}
    {{-- TAB: BROWSE CLANS --}}
    {{-- ===================================================================== --}}
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
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl" wire:key="clan-{{ $row['clan']->id }}">
                        <div class="flex-1 min-w-0">
                            <p class="font-mono text-sm font-bold text-foreground truncate">
                                {{ $row['clan']->name }}
                                @if ($row['clan']->tag)
                                    <span class="text-muted font-normal">[{{ $row['clan']->tag }}]</span>
                                @endif
                            </p>
                            <p class="font-mono text-xs text-muted mt-0.5">{{ $row['clan']->members_count }} / {{ \App\Livewire\Clans::MAX_MEMBERS }} members</p>
                        </div>

                        @switch($row['relation'])
                            @case('member')
                                <span class="px-4 py-1.5 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg">Joined</span>
                                @break
                            @case('pending')
                                <span class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg">Request Sent</span>
                                @break
                            @default
                                <button wire:click="sendJoinRequest({{ $row['clan']->id }})"
                                    class="px-4 py-1.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
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

    {{-- ===================================================================== --}}
    {{-- TAB: CREATE CLAN --}}
    {{-- ===================================================================== --}}
    @if ($tab === 'create')
        <form wire:submit.prevent="createClan" class="max-w-md space-y-4">
            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">Clan Name</label>
                <input type="text" wire:model="newName" maxlength="40"
                    placeholder="e.g. Speed Demons"
                    class="w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
                @error('newName')
                    <p class="font-mono text-xs text-red-400 mt-1.5">{{ $message }}</p>
                @enderror
            </div>
            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">Tag (optional)</label>
                <input type="text" wire:model="newTag" maxlength="6"
                    placeholder="e.g. SPD"
                    class="w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
                @error('newTag')
                    <p class="font-mono text-xs text-red-400 mt-1.5">{{ $message }}</p>
                @enderror
            </div>
            <button type="submit"
                class="px-5 py-2.5 font-mono text-xs font-bold text-background bg-gold hover:bg-gold/90 rounded-lg transition">
                Create Clan
            </button>
        </form>
    @endif

    {{-- ===== REAL-TIME =====
         Subscription Echo ke clan.{id} DIPEGANG oleh toast global di layout
         (satu-satunya subscriber, agar tak dobel). Halaman ini cukup mendengar
         event window 'clan-updated-remote' yang diteruskan toast lalu
         menyegarkan datanya. --}}
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
