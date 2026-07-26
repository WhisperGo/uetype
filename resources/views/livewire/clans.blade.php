{{-- Clans page: tabbed as My Clan (hero card, join requests, roster), Browse (search
     and join), and Create (new clan form). Real-time updates via the global toast subscriber. --}}
<div class="py-10">
    <x-page-container>

    <h1 class="font-display text-fluid-title tracking-wide text-foreground mb-4">{{ __('clan.title') }}</h1>

    <!-- ===== TABS ===== -->
    {{-- <div class="border-b border-white/10 mb-6">
        {{-- mb-8: gap between the tab underline and the content below (the wrapper div that
             once carried the shared border-b is commented out, so -mb-px is no longer needed). --}}
        <nav class="flex mb-4 font-mono text-sm" aria-label="{{ __('clan.tab.aria') }}">
            <button wire:click="setTab('my-clan')"
                @class([
                    'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                    'border-gold text-foreground font-semibold' => $tab === 'my-clan',
                    'border-transparent text-muted hover:text-foreground' => $tab !== 'my-clan',
                ])>
                {{-- {{ __('clan.tab.my_clan') }} --}}
                {{-- @if ($this->pendingRequests->count() > 0)
                    <span class="ml-1 px-1.5 py-0.5 rounded bg-gold/20 text-gold text-[0.65rem]">{{ $this->pendingRequests->count() }}</span>
                @endif --}}
            </button>
            @unless ($this->myClan)
                <button wire:click="setTab('browse')"
                    @class([
                        'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                        'border-gold text-foreground font-semibold' => $tab === 'browse',
                        'border-transparent text-muted hover:text-foreground' => $tab !== 'browse',
                    ])>
                    {{ __('clan.tab.browse') }}
                </button>
                <button wire:click="setTab('create')"
                    @class([
                        'px-1 py-3 border-b-2 transition-colors whitespace-nowrap',
                        'border-gold text-foreground font-semibold' => $tab === 'create',
                        'border-transparent text-muted hover:text-foreground' => $tab !== 'create',
                    ])>
                    {{ __('clan.tab.create') }}
                </button>
            @endunless
        </nav>
    {{-- </div> --}}

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
                            <span class="px-2.5 py-1 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg">{{ __('clan.level', ['level' => $lvl['level']]) }}</span>
                            <span class="font-mono text-xs text-muted">
                                {{ __('clan.members', ['count' => $this->myClanMembers->count(), 'max' => \App\Livewire\Clans::MAX_MEMBERS]) }}
                                · {{ __('clan.power') }} <span class="text-gold font-bold">{{ number_format($this->myClan->power) }}</span>
                            </span>
                        </div>
                    </div>

                    <div class="flex flex-wrap items-center gap-2 shrink-0">
                        <x-btn-gold as="a" href="{{ route('clan-war.index') }}" wire:navigate>
                            {{ __('clan.clan_war') }}
                        </x-btn-gold>
                        <a href="{{ route('clan-leaderboard.index') }}" wire:navigate
                            class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                            {{ __('clan.leaderboard') }}
                        </a>
                        @if ($this->myMembership->role->value !== 'leader')
                            <button type="button"
                                @click="$dispatch('open-modal', 'confirm-leave')"
                                class="px-4 py-1.5 font-mono text-xs text-danger border border-danger/30 rounded-lg hover:bg-danger/10 transition">
                                {{ __('clan.my_clan.leave') }}
                            </button>
                        @endif
                    </div>
                </div>

                {{-- Level progress bar --}}
                <x-clan.progress :data="$lvl" :show-unit="true" class="mt-5" />
            </div>

            {{-- Incoming join requests (leader only) --}}
            @if ($this->myMembership->role->value === 'leader' && $this->pendingRequests->count() > 0)
                <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('clan.my_clan.join_requests', ['count' => $this->pendingRequests->count()]) }}</p>
                <div class="space-y-3 mb-8">
                    @foreach ($this->pendingRequests as $req)
                        <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl" wire:key="req-{{ $req->id }}">
                            <x-friend-avatar :user="$req->user" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate">{{ $req->user->username }}</p>
                                <p class="font-mono text-xs text-muted mt-0.5">{{ __('clan.user_level', ['level' => $req->user->levelData()['level']]) }}</p>
                            </div>
                            <x-btn-gold wire:click="approveMember({{ $req->id }})">{{ __('clan.my_clan.accept') }}</x-btn-gold>
                            <button wire:click="rejectMember({{ $req->id }})"
                                class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                                {{ __('clan.my_clan.reject') }}
                            </button>
                        </div>
                    @endforeach
                </div>
            @endif

            {{-- approveMember error (e.g. clan full); its own key, rendered near the
                 pending list. This block used to use the 'newName' key from the create-clan
                 form on another tab -- a fragile coupling now removed. --}}
            @error('approveMember')
                <p class="font-mono text-xs text-danger mb-4">{{ $message }}</p>
            @enderror

            <p class="font-mono text-xs uppercase tracking-widest text-muted mb-3">{{ __('clan.members_heading', ['count' => $this->myClanMembers->count()]) }}</p>
            <div class="space-y-3">
                @foreach ($this->myClanMembers as $member)
                    <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group hover:border-white/10 transition {{ $member->role->value === 'leader' ? 'ring-1 ring-gold/20' : '' }}" wire:key="member-{{ $member->id }}">
                        <a href="{{ route('profile.show', $member->user) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                            <x-friend-avatar :user="$member->user" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $member->user->username }}</p>
                                <p class="font-mono text-xs text-muted mt-0.5">{{ __('clan.user_level', ['level' => $member->user->levelData()['level']]) }}</p>
                            </div>
                        </a>
                        @if ($member->role->value === 'leader')
                            <x-clan.role-badge :role="$member->role" />
                        @elseif ($this->myMembership->role->value === 'leader')
                            <button type="button"
                                @click="$dispatch('open-modal', { name: 'confirm-kick', id: {{ $member->id }}, label: @js($member->user->username) })"
                                class="opacity-0 group-hover:opacity-100 px-3 py-1.5 font-mono text-xs text-danger border border-danger/30 rounded-lg hover:bg-danger/10 transition">
                                {{ __('clan.my_clan.kick') }}
                            </button>
                        @endif
                    </div>
                @endforeach
            </div>
        @else
            <x-empty-state :title="__('clan.empty.no_clan_title')" :body="__('clan.empty.no_clan_body')">
                <x-slot:cta>
                    <x-btn-gold size="lg" wire:click="setTab('browse')">{{ __('clan.tab.browse') }}</x-btn-gold>
                    <x-btn-ghost size="lg" wire:click="setTab('create')">{{ __('clan.tab.create') }}</x-btn-ghost>
                    <x-btn-ghost as="a" size="lg" href="{{ route('clan-leaderboard.index') }}" wire:navigate>
                        {{ __('clan.leaderboard') }}
                    </x-btn-ghost>
                </x-slot:cta>
            </x-empty-state>
        @endif
    @endif

    {{-- TAB: BROWSE CLANS --}}
    @if ($tab === 'browse')
        <div class="relative mb-6">
            <svg class="absolute left-4 top-1/2 -translate-y-1/2 w-4 h-4 text-muted" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                <path stroke-linecap="round" stroke-linejoin="round" d="M21 21l-4.35-4.35M11 18a7 7 0 100-14 7 7 0 000 14z" />
            </svg>
            <input type="text" wire:model.live.debounce.300ms="search"
                placeholder="{{ __('clan.browse.search_placeholder') }}"
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
                                    {{ __('clan.level', ['level' => $row['clan']->levelData()['level']]) }}
                                    · {{ __('clan.members', ['count' => $row['clan']->members_count, 'max' => \App\Livewire\Clans::MAX_MEMBERS]) }}
                                    · {{ __('clan.power_inline', ['value' => number_format($row['clan']->power)]) }}
                                </p>
                            </div>
                        </a>

                        @switch($row['relation'])
                            @case('member')
                                <span class="px-4 py-1.5 font-mono text-xs font-bold text-gold border border-gold/40 rounded-lg shrink-0">{{ __('clan.browse.joined') }}</span>
                                @break
                            @case('pending')
                                <span class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg shrink-0">{{ __('clan.browse.request_sent') }}</span>
                                @break
                            @default
                                <x-btn-gold class="shrink-0" wire:click="sendJoinRequest({{ $row['clan']->id }})">
                                    {{ __('clan.browse.join') }}
                                </x-btn-gold>
                        @endswitch
                    </div>
                @endforeach
            </div>
        @else
            {{-- Body passed via slot, not a prop: its text depends on whether a search query exists. --}}
            <x-empty-state :title="__('clan.empty.no_results_title')">
                {{ trim($search) !== '' ? __('clan.empty.no_results_body', ['query' => trim($search)]) : __('clan.empty.no_results_alt') }}
            </x-empty-state>
        @endif
    @endif

    {{-- TAB: CREATE CLAN --}}
    @if ($tab === 'create')
        <form wire:submit.prevent="createClan" class="max-w-lg space-y-5">
            {{-- Preview + identity --}}
            <div class="flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl">
                <x-clan-emblem :clan="(object) ['name' => $newName ?: '?', 'emblem' => $newEmblem, 'emblem_color' => $newEmblemColor]" size="lg" />
                <div class="min-w-0">
                    <p class="font-mono text-sm font-bold text-foreground truncate">{{ $newName ?: __('clan.create.preview_name') }}
                        @if (trim($newTag) !== '')<span class="text-muted font-normal">[{{ $newTag }}]</span>@endif
                    </p>
                    <p class="font-mono text-xs text-muted mt-0.5 truncate">{{ $newDescription ?: __('clan.create.preview_desc') }}</p>
                </div>
            </div>

            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">{{ __('clan.create.name_label') }}</label>
                <input type="text" wire:model.live="newName" maxlength="40"
                    placeholder="{{ __('clan.create.name_placeholder') }}"
                    class="w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
                @error('newName')<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">{{ __('clan.create.tag_label') }}</label>
                <input type="text" wire:model.live="newTag" maxlength="6"
                    placeholder="{{ __('clan.create.tag_placeholder') }}"
                    class="w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition">
                @error('newTag')<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
            </div>

            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">{{ __('clan.create.desc_label') }}</label>
                <textarea wire:model.live="newDescription" maxlength="160" rows="2"
                    placeholder="{{ __('clan.create.desc_placeholder') }}"
                    class="w-full mt-1.5 px-4 py-3 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-gold/50 focus:ring-0 transition resize-none"></textarea>
                @error('newDescription')<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
            </div>

            {{-- Emblem picker --}}
            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">{{ __('clan.create.emblem_label') }}</label>
                <div class="mt-2 grid grid-cols-6 sm:grid-cols-8 gap-2">
                    @foreach (\App\Support\ClanEmblem::icons() as $key => $path)
                        <button type="button" wire:click="$set('newEmblem', '{{ $key }}')"
                            aria-label="{{ __('clan.aria.emblem', ['key' => $key]) }}"
                            @class([
                                'aspect-square flex items-center justify-center rounded-lg border transition',
                                'border-gold bg-gold/15 text-gold' => $newEmblem === $key,
                                'border-white/10 text-muted hover:text-foreground hover:border-white/20' => $newEmblem !== $key,
                            ])>
                            <svg class="w-5 h-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.75" stroke-linecap="round" stroke-linejoin="round"><path d="{{ $path }}" /></svg>
                        </button>
                    @endforeach
                </div>
                @error('newEmblem')<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
            </div>

            {{-- Color picker --}}
            <div>
                <label class="font-mono text-xs uppercase tracking-widest text-muted">{{ __('clan.create.color_label') }}</label>
                <div class="mt-2 flex flex-wrap gap-2.5">
                    @foreach (\App\Support\ClanEmblem::colors() as $key => $hex)
                        <button type="button" wire:click="$set('newEmblemColor', '{{ $key }}')"
                            aria-label="{{ __('clan.aria.color', ['key' => $key]) }}"
                            class="w-8 h-8 rounded-full border-2 transition {{ $newEmblemColor === $key ? 'ring-2 ring-offset-2 ring-offset-background ring-white/60 border-white/60' : 'border-white/10 hover:border-white/30' }}"
                            style="background-color: {{ $hex }};"></button>
                    @endforeach
                </div>
                @error('newEmblemColor')<p class="font-mono text-xs text-danger mt-1.5">{{ $message }}</p>@enderror
            </div>

            <x-btn-gold type="submit" size="xl">{{ __('clan.create.submit') }}</x-btn-gold>
        </form>
    @endif

    {{-- ===== CONFIRMATION MODALS (themed, replace native wire:confirm) ===== --}}
    @if ($this->myClan)
        {{-- Leave Clan (regular member) --}}
        <x-modal name="confirm-leave" maxWidth="md">
            <div class="p-6">
                <p class="font-mono text-sm font-bold text-foreground">{{ __('clan.modal.leave_title', ['name' => $this->myClan->name]) }}</p>
                <p class="font-mono text-xs text-muted mt-2">{{ __('clan.modal.leave_body') }}</p>
                <div class="flex justify-end gap-3 mt-6">
                    <button type="button" @click="$dispatch('close-modal', 'confirm-leave')"
                        class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                        {{ __('clan.modal.cancel') }}
                    </button>
                    <button type="button" wire:click="leaveClan" @click="$dispatch('close-modal', 'confirm-leave')"
                        class="px-4 py-2 font-mono text-xs font-bold text-foreground bg-danger hover:bg-danger/80 rounded-lg transition">
                        {{ __('clan.modal.leave_confirm') }}
                    </button>
                </div>
            </div>
        </x-modal>

        {{-- Kick member (leader): one modal, target stored from the event --}}
        @if ($this->myMembership->role->value === 'leader')
            <div x-data="{ kickId: null, kickLabel: '' }"
                @open-modal.window="if ($event.detail?.name === 'confirm-kick') { kickId = $event.detail.id; kickLabel = $event.detail.label; $dispatch('open-modal', 'confirm-kick') }">
                <x-modal name="confirm-kick" maxWidth="md">
                    <div class="p-6">
                        <p class="font-mono text-sm font-bold text-foreground">{{ __('clan.modal.kick_title') }}</p>
                        <p class="font-mono text-xs text-gold mt-1" x-text="kickLabel"></p>
                        <p class="font-mono text-xs text-muted mt-2">{{ __('clan.modal.kick_body') }}</p>
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" @click="$dispatch('close-modal', 'confirm-kick')"
                                class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                                {{ __('clan.modal.cancel') }}
                            </button>
                            <button type="button" @click="$wire.kickMember(kickId); $dispatch('close-modal', 'confirm-kick')"
                                class="px-4 py-2 font-mono text-xs font-bold text-foreground bg-danger hover:bg-danger/80 rounded-lg transition">
                                {{ __('clan.modal.kick_confirm') }}
                            </button>
                        </div>
                    </div>
                </x-modal>
            </div>
        @endif
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
