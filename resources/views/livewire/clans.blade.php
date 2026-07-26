{{-- Clans page. Two tabs for someone without a clan -- Browse (search and join) and Create
     (new clan form) -- and no tabs at all once you are in one, where the page is simply your
     clan: hero card, join requests, roster.

     'my-clan' is still a real tab STATE even though nothing navigates to it by hand:
     resetToMyClan() switches to it after you create or join a clan. Only its button is gone.

     Real-time updates via the global toast subscriber. --}}
<div class="py-10">
    <x-page-container>

    <h1 class="font-display text-fluid-title tracking-wide text-foreground mb-4">{{ __('clan.title') }}</h1>

    {{-- ===== TABS =====

         Rendered only when there is something to switch BETWEEN. A member of a clan has no
         Browse/Create tabs, so the whole bar -- rule included -- stays out of the document.
         The "My Clan" tab was previously removed by commenting out its LABEL while leaving
         the <button> itself alive: an 8px-wide (px-1 either side) unlabelled control that
         pushed the whole row 12px right of the page title and the cards below it, stayed
         focusable and clickable, and was announced by screen readers as an unnamed button.
         A clan member was left with a bar holding nothing but that empty button.

         Styled as chips, matching the category filters on the Achievements page: a filled
         pill for the active choice, an outlined one for the rest. The previous underline tabs
         carried no gap and only px-1, so "Browse Clans" ended at the exact pixel where
         "Create Clan" began and the pair read as a single run of text. A chip's own border
         and padding make each target legible on its own, and gap-2 separates them.

         aria-label lives on the nav, and aria-current marks the active chip -- the underline
         version conveyed the selection through colour alone. --}}
    @unless ($this->myClan)
        <nav class="flex flex-wrap gap-2 mb-6" aria-label="{{ __('clan.tab.aria') }}">
            @foreach (['browse', 'create'] as $tabKey)
                <button type="button" wire:click="setTab('{{ $tabKey }}')"
                    @if ($tab === $tabKey) aria-current="page" @endif
                    @class([
                        'px-4 py-1.5 rounded-lg border font-mono text-xs font-semibold transition-colors',
                        'bg-brand-bright text-background border-brand-bright' => $tab === $tabKey,
                        'bg-surface/60 text-muted border-white/10 hover:text-foreground hover:border-white/20' => $tab !== $tabKey,
                    ])>
                    {{ __('clan.tab.'.$tabKey) }}
                </button>
            @endforeach
        </nav>
    @endunless

    {{-- TAB: MY CLAN --}}
    @if ($tab === 'my-clan')
        @if ($this->myClan)
            @php $lvl = $this->myClan->levelData(); @endphp

            {{-- EDIT IDENTITY (leader only) -- replaces the hero card while open, rather
                 than sitting below it. The form carries its own live preview, so showing
                 both would put two versions of the same clan on screen at once, the card
                 above still displaying the values being edited away. --}}
            @if ($editing && $this->myMembership->role->value === 'leader')
                <form wire:submit.prevent="saveClanIdentity" class="p-5 sm:p-6 border bg-surface/70 border-white/10 rounded-3xl mb-6 max-w-lg">
                    <p class="font-mono text-xs uppercase tracking-widest text-muted mb-5">{{ __('clan.my_clan.edit_heading') }}</p>

                    <x-clan.identity-fields
                        name="editName" tag="editTag" emblem="editEmblem" color="editEmblemColor" description="editDescription"
                        :name-value="$editName" :tag-value="$editTag" :emblem-value="$editEmblem"
                        :color-value="$editEmblemColor" :description-value="$editDescription" />

                    <div class="flex flex-wrap items-center gap-3 mt-6">
                        <x-btn-gold type="submit">{{ __('clan.my_clan.edit_save') }}</x-btn-gold>
                        <button type="button" wire:click="cancelEditClan"
                            class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                            {{ __('clan.my_clan.edit_cancel') }}
                        </button>
                    </div>
                </form>
            @else

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
                        {{-- The leader has no Leave button: leaving is the one thing they
                             cannot do directly. Their exits live in the Manage menu --
                             transfer (hand it over, then leave) or disband. --}}
                        @if ($this->myMembership->role->value === 'leader')
                            <div class="relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                                <button type="button" @click="open = !open"
                                    class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition inline-flex items-center gap-1.5">
                                    {{ __('clan.my_clan.manage') }}
                                    <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M19 9l-7 7-7-7" /></svg>
                                </button>

                                <div x-show="open" x-cloak
                                    x-transition:enter="transition ease-out duration-150"
                                    x-transition:enter-start="opacity-0 scale-95"
                                    x-transition:enter-end="opacity-100 scale-100"
                                    x-transition:leave="transition ease-in duration-100"
                                    x-transition:leave-start="opacity-100 scale-100"
                                    x-transition:leave-end="opacity-0 scale-95"
                                    class="absolute right-0 top-full z-50 mt-1 w-56 origin-top-right rounded-xl border border-white/10 bg-surface shadow-lg ring-1 ring-black/20 overflow-hidden py-1">
                                    <button type="button" @click="open = false" wire:click="startEditClan"
                                        class="w-full text-left px-4 py-2.5 font-mono text-xs font-bold text-foreground whitespace-nowrap hover:bg-white/5 transition">
                                        {{ __('clan.my_clan.edit') }}
                                    </button>
                                    <button type="button" @click="open = false; $dispatch('open-modal', 'confirm-disband')"
                                        class="w-full text-left px-4 py-2.5 font-mono text-xs font-bold text-danger whitespace-nowrap hover:bg-danger/10 transition border-t border-white/5">
                                        {{ __('clan.my_clan.disband') }}
                                    </button>
                                </div>
                            </div>
                        @else
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
            @endif

            {{-- Incoming join requests. Visible to whoever holds roster powers -- the
                 leader and co-leaders -- matching the server-side gate exactly. --}}
            @php $canManage = $this->myMembership->role->canManageMembers(); @endphp
            @if ($canManage && $this->pendingRequests->count() > 0)
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
                @foreach ($this->myClanMembers as $row)
                    @php
                        $member = $row['member'];
                        $user = $row['user'];
                    @endphp
                    <div @class([
                            'flex items-center gap-4 p-4 border bg-surface/40 border-white/5 rounded-2xl group hover:border-white/10 transition',
                            'ring-1 ring-gold/20' => $member->role->value === 'leader',
                            'ring-1 ring-white/10' => $member->role->value === 'co-leader',
                        ]) wire:key="member-{{ $member->id }}">
                        <a href="{{ route('profile.show', $user) }}" wire:navigate class="flex items-center gap-4 flex-1 min-w-0">
                            {{-- Online state rides on the avatar dot, as it does in the
                                 friends list, instead of spending a slot in the meta line. --}}
                            <x-friend-avatar :user="$user" :online="$row['online']" />
                            <div class="flex-1 min-w-0">
                                <p class="font-mono text-sm font-bold text-foreground truncate group-hover:text-gold transition-colors">{{ $user->username }}</p>

                                {{-- Meta line: level, war contribution, joined. Separated by
                                     middots on one wrapping line rather than stacked rows --
                                     three stacked lines per member turns a 20-person roster
                                     into a wall of text.

                                     "last seen" appears ONLY when offline: for someone
                                     online it would repeat what the dot already says. --}}
                                <p class="font-mono text-xs text-muted mt-0.5 flex flex-wrap items-center gap-x-1.5">
                                    <span>{{ __('clan.user_level', ['level' => $user->levelData()['level']]) }}</span>

                                    @unless ($row['online'])
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $user->last_seen_at ? __('clan.roster.last_seen', ['time' => $user->last_seen_at->diffForHumans(short: true)]) : __('clan.roster.never_seen') }}</span>
                                    @endunless

                                    @if (! is_null($row['contribution']))
                                        <span aria-hidden="true">·</span>
                                        {{-- Zero is called out, not left to read as a plain
                                             number: it is the one value a leader is looking
                                             for, and it is otherwise the quietest thing on
                                             the row. --}}
                                        <span @class(['text-gold' => $row['contribution'] > 0])>
                                            {{ __('clan.roster.war_points', ['points' => number_format($row['contribution'], 0)]) }}
                                        </span>
                                    @endif

                                    @if ($row['joined_at'])
                                        <span aria-hidden="true">·</span>
                                        <span>{{ __('clan.roster.joined', ['time' => $row['joined_at']->diffForHumans(short: true)]) }}</span>
                                    @endif
                                </p>
                            </div>
                        </a>
                        @php
                            $isSelf = $member->user_id === auth()->id();
                            // Mirrors the rank gate in kickMember/changeRole: you may only
                            // act on someone strictly below you, and never on yourself.
                            $canActOnRow = $canManage && ! $isSelf
                                && $member->role->rank() > $this->myMembership->role->rank();
                            $iOwnClan = $this->myMembership->role->canManageClan();
                        @endphp

                        <x-clan.role-badge :role="$member->role" />

                        @if ($canActOnRow)
                            {{-- A member row can now carry up to three actions (promote or
                                 demote, transfer, kick), so they collapse into a kebab --
                                 the same menu pattern as the friends list. Three bare
                                 buttons would not fit a narrow row, and putting a
                                 destructive Kick beside a routine Promote invites misclicks.

                                 Not hidden behind group-hover like the old single button:
                                 hover-only controls are unreachable on touch. --}}
                            <div class="shrink-0 relative" x-data="{ open: false }" @click.outside="open = false" @keydown.escape="open = false">
                                <button type="button" @click="open = !open"
                                    aria-label="{{ __('clan.aria.member_actions', ['name' => $user->username]) }}"
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
                                    class="absolute right-0 top-full z-50 mt-1 w-56 origin-top-right rounded-xl border border-white/10 bg-surface shadow-lg ring-1 ring-black/20 overflow-hidden py-1">

                                    @if ($iOwnClan)
                                        @if ($member->role->value === 'member')
                                            <button type="button"
                                                @click="open = false; $dispatch('open-modal', { name: 'confirm-role', id: {{ $member->id }}, label: @js($user->username), action: 'promote' })"
                                                class="w-full text-left px-4 py-2.5 font-mono text-xs font-bold text-foreground whitespace-nowrap hover:bg-white/5 transition">
                                                {{ __('clan.my_clan.promote') }}
                                            </button>
                                        @else
                                            <button type="button"
                                                @click="open = false; $dispatch('open-modal', { name: 'confirm-role', id: {{ $member->id }}, label: @js($user->username), action: 'demote' })"
                                                class="w-full text-left px-4 py-2.5 font-mono text-xs font-bold text-foreground whitespace-nowrap hover:bg-white/5 transition">
                                                {{ __('clan.my_clan.demote') }}
                                            </button>
                                        @endif

                                        <button type="button"
                                            @click="open = false; $dispatch('open-modal', { name: 'confirm-role', id: {{ $member->id }}, label: @js($user->username), action: 'transfer' })"
                                            class="w-full text-left px-4 py-2.5 font-mono text-xs font-bold text-gold whitespace-nowrap hover:bg-gold/10 transition border-t border-white/5">
                                            {{ __('clan.my_clan.transfer') }}
                                        </button>
                                    @endif

                                    <button type="button"
                                        @click="open = false; $dispatch('open-modal', { name: 'confirm-kick', id: {{ $member->id }}, label: @js($user->username) })"
                                        class="w-full text-left px-4 py-2.5 font-mono text-xs font-bold text-danger whitespace-nowrap hover:bg-danger/10 transition border-t border-white/5">
                                        {{ __('clan.my_clan.kick') }}
                                    </button>
                                </div>
                            </div>
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
                                {{-- Status as plain text, withdraw as its own button beside
                                     it -- the same pairing the friends page uses for a sent
                                     request.

                                     This replaces a single control that swapped its label on
                                     hover: that hid the action entirely on touch, where
                                     there is no hover state, and made the row's meaning
                                     depend on where the pointer happened to be. Two elements,
                                     both always visible, say what is true and what you can
                                     do about it at the same time. --}}
                                <div class="flex items-center gap-3 shrink-0">
                                    <span class="font-mono text-xs text-muted">{{ __('clan.browse.request_sent') }}</span>
                                    <button type="button" wire:click="cancelJoinRequest({{ $row['clan']->id }})"
                                        class="px-4 py-1.5 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                                        {{ __('clan.browse.cancel_request') }}
                                    </button>
                                </div>
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
            <x-clan.identity-fields
                name="newName" tag="newTag" emblem="newEmblem" color="newEmblemColor" description="newDescription"
                :name-value="$newName" :tag-value="$newTag" :emblem-value="$newEmblem"
                :color-value="$newEmblemColor" :description-value="$newDescription" />

            <x-btn-gold type="submit" size="xl">{{ __('clan.create.submit') }}</x-btn-gold>
        </form>
    @endif

    {{-- ===== CONFIRMATION MODALS (themed, replace native wire:confirm) ===== --}}
    @if ($this->myClan)
        {{-- Leave Clan (anyone but the leader, whose exit is transfer or disband).
             Gated on the same condition as its trigger button: rendered for a leader it
             would be a modal nothing can open, yet still reachable by dispatching the
             event by hand -- offering a confirmation for an action the server refuses. --}}
        @if ($this->myMembership->role->value !== 'leader')
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
        @endif

        {{-- Role changes (leader only): promote, demote and transfer share ONE modal.
             All three ask the same question -- "apply this role change to this member?" --
             so the copy is swapped from the dispatched action rather than rendering three
             near-identical modals per roster row. --}}
        @if ($this->myMembership->role->value === 'leader')
            <div x-data="{
                    roleId: null,
                    roleLabel: '',
                    action: null,
                    copy: {
                        promote: @js([
                            'title' => __('clan.modal.promote_title', ['name' => ':name']),
                            'body' => __('clan.modal.promote_body'),
                            'confirm' => __('clan.modal.promote_confirm'),
                        ]),
                        demote: @js([
                            'title' => __('clan.modal.demote_title', ['name' => ':name']),
                            'body' => __('clan.modal.demote_body'),
                            'confirm' => __('clan.modal.demote_confirm'),
                        ]),
                        transfer: @js([
                            'title' => __('clan.modal.transfer_title', ['name' => ':name']),
                            'body' => __('clan.modal.transfer_body'),
                            'confirm' => __('clan.modal.transfer_confirm'),
                        ]),
                    },
                    text(key) {
                        if (! this.action) return '';
                        return this.copy[this.action][key].replace(':name', this.roleLabel);
                    },
                    run() {
                        if (this.action === 'promote') $wire.promoteMember(this.roleId);
                        else if (this.action === 'demote') $wire.demoteMember(this.roleId);
                        else if (this.action === 'transfer') $wire.transferLeadership(this.roleId);
                        $dispatch('close-modal', 'confirm-role');
                    },
                }"
                @open-modal.window="if ($event.detail?.name === 'confirm-role') { roleId = $event.detail.id; roleLabel = $event.detail.label; action = $event.detail.action; $dispatch('open-modal', 'confirm-role') }">
                <x-modal name="confirm-role" maxWidth="md">
                    <div class="p-6">
                        <p class="font-mono text-sm font-bold text-foreground" x-text="text('title')"></p>
                        <p class="font-mono text-xs text-muted mt-2" x-text="text('body')"></p>
                        <div class="flex justify-end gap-3 mt-6">
                            <button type="button" @click="$dispatch('close-modal', 'confirm-role')"
                                class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                                {{ __('clan.modal.cancel') }}
                            </button>
                            {{-- Transfer is styled gold, not danger: it hands the clan on
                                 rather than destroying anything, and colouring it red next
                                 to the genuinely destructive Kick would flatten the
                                 difference between them. --}}
                            <button type="button" @click="run()"
                                :class="action === 'transfer'
                                    ? 'bg-gold text-background hover:bg-gold/80'
                                    : 'bg-white/10 text-foreground hover:bg-white/15'"
                                class="px-4 py-2 font-mono text-xs font-bold rounded-lg transition"
                                x-text="text('confirm')"></button>
                        </div>
                    </div>
                </x-modal>
            </div>

            {{-- Disband (leader only): retype-the-name confirmation, the same guard the
                 account-deletion flow uses, because this is equally irreversible. --}}
            <x-modal name="confirm-disband" maxWidth="md">
                <div class="p-6">
                    <p class="font-mono text-sm font-bold text-foreground">{{ __('clan.modal.disband_title', ['name' => $this->myClan->name]) }}</p>
                    <p class="font-mono text-xs text-muted mt-2">{{ __('clan.modal.disband_body') }}</p>

                    <label class="block font-mono text-xs text-muted mt-4 mb-1.5">{{ __('clan.modal.disband_prompt', ['name' => $this->myClan->name]) }}</label>
                    <input type="text" wire:model="confirmDisbandName"
                        class="w-full px-4 py-2.5 bg-surface/40 border border-white/10 rounded-xl font-mono text-sm text-foreground placeholder-muted focus:border-danger/50 focus:ring-0 transition">
                    @error('disband')<p class="font-mono text-xs text-danger mt-2">{{ $message }}</p>@enderror

                    <div class="flex justify-end gap-3 mt-6">
                        <button type="button" @click="$dispatch('close-modal', 'confirm-disband')"
                            class="px-4 py-2 font-mono text-xs text-muted border border-white/10 rounded-lg hover:text-foreground hover:bg-white/5 transition">
                            {{ __('clan.modal.cancel') }}
                        </button>
                        {{-- Deliberately does NOT close the modal on click: disband can fail
                             server-side (name mismatch, war in progress) and the error is
                             rendered right here. Closing optimistically would hide it. --}}
                        <button type="button" wire:click="disbandClan"
                            class="px-4 py-2 font-mono text-xs font-bold text-foreground bg-danger hover:bg-danger/80 rounded-lg transition">
                            {{ __('clan.modal.disband_confirm') }}
                        </button>
                    </div>
                </div>
            </x-modal>
        @endif

        {{-- Kick member: one modal, target stored from the event --}}
        @if ($this->myMembership->role->canManageMembers())
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
