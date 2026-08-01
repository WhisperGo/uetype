@php
    // Flags for the leave beacon (#2) and leave-confirm nav interceptor (#3). Rendered as
    // data-* on the root so they re-render on every Livewire morph (unlike a one-shot
    // @script), and read by resources/js/multiplayer-nav.js at click / page-unload time.
    $mpMember = ($this->step === 'waiting' || $this->step === 'racing')
        ? $this->roomData?->members->firstWhere('user_id', Auth::id())
        : null;
    $mpInRoom = $mpMember !== null;
    $mpWaiting = $this->step === 'waiting';
@endphp
<div class="max-w-5xl px-4 mx-auto py-6 sm:px-6 lg:px-8 text-foreground"
    x-data="{
        kickId: null,
        kickName: '',
        askKick(id, name) {
            this.kickId = id;
            this.kickName = name;
            $dispatch('open-modal', 'confirm-kick-member');
        },
        confirmKick() {
            if (this.kickId !== null) { $wire.kickMember(this.kickId); }
            $dispatch('close-modal', 'confirm-kick-member');
        },
        {{-- Per-friend invite cooldown: after inviting, that friend's button shows a live
             5-second countdown, then becomes available again -- no page refresh needed.
             cooldowns maps friendId => seconds remaining (0/absent = invitable). --}}
        inviteCooldown: 5,
        cooldowns: {},
        openInvite() {
            $dispatch('open-modal', 'invite-friends');
        },
        inviteRemaining(id) {
            return this.cooldowns[id] || 0;
        },
        invite(id) {
            if (this.inviteRemaining(id) > 0) return;
            $wire.invitePlayer(id);

            // Start the countdown for this friend and tick it down once per second.
            this.cooldowns[id] = this.inviteCooldown;
            const timer = setInterval(() => {
                this.cooldowns[id] = (this.cooldowns[id] || 0) - 1;
                if (this.cooldowns[id] <= 0) {
                    delete this.cooldowns[id];
                    clearInterval(timer);
                }
            }, 1000);
        },
    }"
    data-mp-flags
    data-mp-in-room="{{ $mpInRoom ? '1' : '0' }}"
    data-mp-waiting="{{ $mpWaiting ? '1' : '0' }}"
    data-mp-leave-beacon="{{ route('multiplayer.leave-beacon') }}"
    data-mp-leave-confirm="{{ route('multiplayer.leave-confirm') }}"
    data-mp-page="{{ route('multiplayer.lobby') }}">
    <!-- ===== 1. CHOOSE PAGE: CREATE OR JOIN ROOM ===== -->
    @if ($this->step === 'choose')
        <div class="grid grid-cols-1 md:grid-cols-2 gap-12 md:gap-24 items-stretch mt-10 relative w-full">
            <div
                class="flex flex-col items-center justify-center p-8 border bg-surface/50 border-border/40 rounded-3xl text-center shadow-lg relative overflow-hidden group w-full">
                <div class="w-20 h-20 mb-6 flex items-center justify-center text-4xl transition duration-300">
                    <img src="/icon/uetype_mascot.png" alt="{{ __('multiplayer.create_room') }}">
                </div>
                <h3 class="text-xl font-mono font-bold tracking-wider text-foreground mb-2 uppercase">{{ __('multiplayer.create_room') }}</h3>
                <p class="text-sm text-muted max-w-xs mb-8">{{ __('multiplayer.create_room_desc') }}</p>
                <button wire:click="createRoom"
                    class="px-6 py-3 bg-gold hover:bg-secondary-7 text-background font-mono font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                    {{ __('multiplayer.create_room') }}
                </button>
            </div>

            <div
                class="hidden md:flex absolute inset-y-0 left-1/2 -translate-x-1/2 items-center justify-center pointer-events-none">
                <div class="w-[1px] h-full bg-border/50 relative flex items-center justify-center">
                    <div
                        class="absolute w-12 h-12 rounded-full border-2 border-gold bg-background flex items-center justify-center font-mono text-xs font-bold tracking-wider text-foreground shadow-xl">
                        {{ __('multiplayer.or') }}
                    </div>
                </div>
            </div>

            <div
                class="flex flex-col items-center justify-center p-8 border bg-surface/50 border-border/40 rounded-3xl text-center shadow-lg relative overflow-hidden group w-full"
                x-data="{
                    syncBoxes() {
                        const boxes = [...$refs.codeBoxes.querySelectorAll('input')];
                        $wire.set('joinCodeInput', boxes.map(box => box.value));
                    },
                    distribute(event) {
                        event.preventDefault();
                        const raw = (event.clipboardData || window.clipboardData).getData('text');
                        const chars = raw.toUpperCase().replace(/[^A-Z0-9]/g, '').slice(0, 6).split('');
                        const code = Array.from({ length: 6 }, (_, i) => chars[i] ?? '');
                        const boxes = [...$refs.codeBoxes.querySelectorAll('input')];
                        boxes.forEach((box, i) => { box.value = code[i]; });
                        $wire.set('joinCodeInput', code);
                        const lastFilled = Math.min(chars.length, 6) - 1;
                        (boxes[lastFilled] ?? boxes[0])?.focus();
                    },
                    backspace(event) {
                        if (event.target.value.length !== 0) { return; }
                        const prev = event.target.previousElementSibling;
                        if (!prev) { return; }
                        event.preventDefault();
                        prev.value = '';
                        prev.focus();
                        this.syncBoxes();
                    }
                }">
                <div class="w-20 h-20 mb-6 flex items-center justify-center text-4xl transition duration-300">
                    <img src="/icon/uetype_mascot.png" alt="{{ __('multiplayer.join_room') }}">
                </div>
                <h3 class="text-xl font-mono font-bold tracking-wider text-foreground mb-2 uppercase">{{ __('multiplayer.join_room') }}</h3>
                <p class="text-sm text-muted max-w-xs mb-6">{{ __('multiplayer.join_room_desc') }}</p>

                <div class="flex gap-2 mb-6" x-ref="codeBoxes">
                    @foreach (range(0, 5) as $index)
                        <input type="text" maxlength="1" wire:key="join-box-{{ $index }}"
                            class="w-12 h-14 text-center font-mono text-xl font-bold uppercase bg-background border border-border/40 rounded-xl focus:border-brand-bright focus:ring-0 text-foreground"
                            x-on:paste="distribute($event)"
                            x-on:input="$el.value = $el.value.toUpperCase()"
                            x-on:keydown.enter.prevent="syncBoxes(); $wire.joinRoom()"
                            x-on:keydown.backspace="backspace($event)"
                            x-on:keyup="if($event.key !== 'Backspace' && $el.value.length == 1 && {{ $index }} < 5) { $el.nextElementSibling.focus() }" />
                    @endforeach
                </div>

                <button type="button" x-on:click="syncBoxes(); $wire.joinRoom()"
                    class="px-8 py-3 border border-border/40 text-foreground hover:bg-foreground/5 font-mono font-semibold uppercase tracking-wider rounded-xl transition duration-200">
                    {{ __('multiplayer.join_room') }}
                </button>

                @if (session()->has('error'))
                    <p class="mt-4 flex items-center justify-center gap-1.5 font-mono text-xs text-danger">
                        <svg class="w-3.5 h-3.5 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
                        </svg>
                        <span>{{ session('error') }}</span>
                    </p>
                @endif
            </div>
        </div>

        <!-- HOW IT WORKS PANEL -->
        <div
            class="mt-20 pt-10 border-t border-border/30 flex flex-col md:flex-row items-center justify-center gap-6 md:gap-8 select-none">
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-gold bg-elevated/80 flex items-center justify-center font-mono text-xs font-bold text-foreground shadow-inner">
                    1</div>
                <span class="font-mono text-sm text-muted tracking-wide">{{ __('multiplayer.how_1') }}</span>
            </div>
            <span class="text-muted/40 font-mono text-sm hidden md:block">-</span>
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-gold bg-elevated/80 flex items-center justify-center font-mono text-xs font-bold text-foreground shadow-inner">
                    2</div>
                <span class="font-mono text-sm text-muted tracking-wide">{{ __('multiplayer.how_2') }}</span>
            </div>
            <span class="text-muted/40 font-mono text-sm hidden md:block">-</span>
            <div class="flex items-center gap-3">
                <div
                    class="w-7 h-7 rounded-full border border-gold bg-elevated/80 flex items-center justify-center font-mono text-xs font-bold text-foreground shadow-inner">
                    3</div>
                <span class="font-mono text-sm text-muted tracking-wide">{{ __('multiplayer.how_3') }}</span>
            </div>
        </div>
    @endif

    <!-- ===== 2. WAITING ROOM ===== -->
    @if ($this->step === 'waiting' && $this->roomData && !$showResultModal)
        {{-- Lobby updates (join/ready/leave) are pushed via WebSocket .room.updated -> Livewire.dispatch('room-updated'), not polling. --}}
        <div class="space-y-8">
            <div
                x-data="{
                    copied: false,
                    copyTimer: null,
                    async copyRoomCode() {
                        if (this.copied) { return; }

                        await navigator.clipboard.writeText(@js($this->roomData->code));

                        this.copied = true;
                        clearTimeout(this.copyTimer);
                        this.copyTimer = setTimeout(() => { this.copied = false; }, 4000);
                    }
                }"
                class="p-6 border bg-surface/40 border-border/40 rounded-2xl flex flex-col sm:flex-row items-center justify-between gap-4">
                {{-- Centered while stacked (mobile); left-aligned once the row lays out at sm. --}}
                <div class="text-center sm:text-left">
                    <span class="text-xs font-mono tracking-widest text-muted uppercase">{{ __('multiplayer.room_code_share') }}</span>
                    <h2 class="text-fluid-title font-mono font-black tracking-[0.3em] text-foreground mt-1">
                        {{ $this->roomData->code }}</h2>
                </div>

                {{-- Race content language. HOST edits it (buttons regenerate the room text and
                     broadcast); everyone else sees a read-only badge of the room's language. --}}
                @php $roomLang = $this->roomData->language ?? 'en'; @endphp
                <div class="flex flex-col items-center gap-2">
                    <span class="text-xs font-mono tracking-widest text-muted uppercase">{{ __('multiplayer.race_language') }}</span>
                    @if ($this->isHost)
                        <div class="inline-flex items-stretch gap-0.5 p-[3px] rounded-lg bg-surface border border-border"
                            role="group" aria-label="{{ __('multiplayer.race_language') }}">
                            @foreach (['en' => 'EN', 'id' => 'ID'] as $code => $label)
                                <button type="button" aria-label="{{ $label }}"
                                    aria-pressed="{{ $roomLang === $code ? 'true' : 'false' }}"
                                    wire:click="setRaceLang('{{ $code }}')"
                                    class="px-[16px] py-[5px] rounded-md text-small font-mono font-bold transition-all duration-150 outline-none focus-visible:ring-2 focus-visible:ring-brand {{ $roomLang === $code ? 'bg-brand text-foreground' : 'text-muted hover:text-foreground' }}">{{ $label }}</button>
                            @endforeach
                        </div>
                    @else
                        <span class="px-[16px] py-[5px] rounded-md bg-surface border border-border text-small font-mono font-bold text-foreground uppercase">{{ $roomLang }}</span>
                    @endif
                </div>

                <button type="button"
                    x-on:click.prevent="copyRoomCode()"
                    x-bind:disabled="copied"
                    x-bind:class="copied ? 'bg-active text-background border-active/35 cursor-default' : 'bg-foreground/5 border-border/40 hover:bg-foreground/10'"
                    class="inline-grid appearance-none place-items-center px-5 py-2.5 border font-mono text-xs font-bold uppercase tracking-wider rounded-xl transition-colors duration-200 disabled:pointer-events-none disabled:opacity-100"
                    aria-live="polite">
                    <span class="[grid-area:1/1] translate-y-[0.5px] font-mono text-xs font-bold uppercase leading-[1.1] tracking-wider text-current"
                        x-bind:class="copied ? 'invisible' : 'visible'">{{ __('multiplayer.copy_code') }}</span>
                    <span class="[grid-area:1/1] translate-y-[0.5px] font-mono text-xs font-bold uppercase leading-[1.1] tracking-wider text-current"
                        x-bind:class="copied ? 'visible' : 'invisible'">{{ __('multiplayer.copied') }}</span>
                </button>
            </div>

            <div>
                <div class="flex justify-between items-center mb-4 gap-3">
                    <h3 class="text-xs uppercase tracking-widest text-muted font-mono font-bold">{{ __('multiplayer.players') }}</h3>
                    <div class="flex items-center gap-3">
                        @if ($this->spectatorCount > 0)
                            <div class="relative" x-data="{ open: false }">
                                <button type="button" x-on:click="open = !open" x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                                    class="flex items-center gap-1.5 px-2.5 py-1 rounded-lg border border-border/40 bg-foreground/5 hover:bg-foreground/10 transition">
                                    <span class="text-sm leading-none">&#128065;</span>
                                    <span class="text-[11px] font-mono font-bold text-muted">{{ __('multiplayer.spectators_watching', ['count' => $this->spectatorCount]) }}</span>
                                </button>
                                <div x-show="open" x-cloak x-transition.opacity
                                    x-on:mouseenter="open = true" x-on:mouseleave="open = false"
                                    class="absolute right-0 z-30 mt-2 w-56 p-3 rounded-xl border border-border/40 bg-elevated shadow-xl">
                                    <span class="block text-[10px] font-mono uppercase tracking-widest text-muted mb-2">{{ __('multiplayer.spectator_list_title') }}</span>
                                    <div class="space-y-1.5 max-h-48 overflow-y-auto">
                                        @foreach ($this->spectators as $spectator)
                                            <div class="flex items-center gap-2">
                                                <x-friend-avatar :user="$spectator->user" size="w-6 h-6" shape="rounded-md"
                                                    bg="bg-foreground/5" :bordered="false" fallback-size="w-4/5 h-4/5" />
                                                <span class="font-mono text-xs text-foreground/90 truncate">{{ $spectator->user->username }}</span>
                                                @if ($spectator->user_id === $this->roomData->host_id)
                                                    <span class="text-[9px] font-mono font-bold uppercase tracking-wider text-gold shrink-0">{{ __('multiplayer.host') }}</span>
                                                @elseif ($this->isHost)
                                                    {{-- Host may also kick a spectator (they hold a slot too). Opens the overlay. --}}
                                                    {{-- Deliberately NOT <x-icon-button>: the circled-X-in-danger-red is its
                                                         own affordance and that component is neutral-toned by design. What is
                                                         borrowed is the RULE -- the visible circle stays 20px, the click box is
                                                         44px via min-w/min-h, and `-my-3` keeps the taller box from stretching
                                                         this list row. A destructive control was the smallest target in the
                                                         app at 20x20px. --}}
                                                    <button type="button"
                                                        x-on:click="askKick({{ $spectator->user_id }}, @js($spectator->user->username))"
                                                        title="{{ __('multiplayer.kick_player', ['name' => $spectator->user->username]) }}"
                                                        class="ml-auto -my-3 min-w-[44px] min-h-[44px] flex items-center justify-center transition shrink-0 text-danger/70 hover:text-danger focus:outline-none focus-visible:ring-1 focus-visible:ring-danger/50 rounded-lg"
                                                        aria-label="{{ __('multiplayer.kick_player', ['name' => $spectator->user->username]) }}">
                                                        <span class="w-5 h-5 rounded-full flex items-center justify-center border border-danger/40 bg-danger/5 hover:bg-danger/20 transition">
                                                            <svg class="w-3 h-3" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                                            </svg>
                                                        </span>
                                                    </button>
                                                @endif
                                            </div>
                                        @endforeach
                                    </div>
                                </div>
                            </div>
                        @endif
                        <span class="text-xs font-mono text-gold font-bold">{{ __('multiplayer.joined', ['count' => $this->orderedMembers->count()]) }}</span>
                    </div>
                </div>

                {{-- Mobile: flex-wrap + justify-center so the lone 5th slot (2 per row -> 2/2/1)
                     sits CENTERED instead of orphaned on the left. From sm up it's the original
                     even 5-column grid. Each card is `w-[calc(50%-0.5rem)]` on mobile (two per
                     row with the gap-4) and `sm:w-auto` so the grid tracks size it at sm+. --}}
                <div class="flex flex-wrap justify-center gap-4 sm:grid sm:grid-cols-5">
                    @foreach (range(0, 4) as $i)
                        @php
                            $member = $this->orderedMembers->get($i);
                        @endphp
                        @if ($member)
                            @php
                                // The host may kick any OTHER member (not themselves) while waiting.
                                $canKick = $this->isHost && $member->user_id !== $this->roomData->host_id;
                            @endphp
                            <div
                                class="w-[calc(50%-0.5rem)] sm:w-auto p-5 border flex flex-col items-center justify-center text-center rounded-2xl relative transition duration-300 {{ $member->user_id === Auth::id() ? 'bg-elevated/60 border-brand-bright' : 'bg-surface/40 border-border/40' }}">
                                @if ($canKick)
                                    {{-- Host-only kick control: a small circled X in the card corner. --}}
                                    {{-- Opens the confirm-kick overlay (see bottom of view) instead of a browser confirm. --}}
                                    {{-- The 24px circle stays; the CLICK BOX is 44px and is allowed to overhang the
                                         card corner (`-top-1 -right-1`, so it extends past the rounded edge). On a
                                         phone this card is ~160px wide in `grid-cols-2`, so a visible 44px circle
                                         would cover a quarter of it -- but a click box may exceed what is drawn.
                                         Shrinking the target instead was not an option: this is destructive, and at
                                         24px it competed with the card's own tap area. --}}
                                    <button type="button"
                                        x-on:click="askKick({{ $member->user_id }}, @js($member->user->username))"
                                        title="{{ __('multiplayer.kick_player', ['name' => $member->user->username]) }}"
                                        class="absolute -top-1 -right-1 min-w-[44px] min-h-[44px] flex items-center justify-center text-danger/70 hover:text-danger transition focus:outline-none focus-visible:ring-1 focus-visible:ring-danger/50 rounded-full"
                                        aria-label="{{ __('multiplayer.kick_player', ['name' => $member->user->username]) }}">
                                        <span class="w-6 h-6 rounded-full flex items-center justify-center border border-danger/40 bg-danger/5 hover:bg-danger/20 hover:border-danger/60 transition">
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                                                <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                            </svg>
                                        </span>
                                    </button>
                                @endif
                                <x-friend-avatar :user="$member->user" size="w-14 h-14" shape="rounded-xl"
                                    bg="bg-foreground/5" :bordered="false" fallback-size="w-4/5 h-4/5" class="mb-3" />
                                <span
                                    class="font-mono text-sm font-bold truncate max-w-[100px] text-foreground">{{ $member->user->username }}</span>
                                <div class="mt-2.5 flex items-center gap-1.5">
                                    @if ($member->user_id === $this->roomData->host_id)
                                        <span class="w-1.5 h-1.5 rounded-full bg-gold"></span>
                                        <span class="text-[10px] font-mono font-bold uppercase tracking-wider text-gold">{{ __('multiplayer.host') }}</span>
                                    @else
                                        <span class="w-1.5 h-1.5 rounded-full {{ $member->is_ready ? 'bg-active' : 'bg-muted' }}"></span>
                                        <span class="text-[10px] font-mono font-bold uppercase tracking-wider {{ $member->is_ready ? 'text-active' : 'text-muted' }}">{{ $member->is_ready ? __('multiplayer.ready') : __('multiplayer.not_ready') }}</span>
                                    @endif
                                </div>
                            </div>
                        @else
                            {{-- Empty slot: click to invite a friend. Any member may invite.
                                 The dashed card turns into a "+ Invite" affordance on hover. --}}
                            <button type="button" x-on:click="openInvite()"
                                class="w-[calc(50%-0.5rem)] sm:w-auto group p-5 border border-dashed border-border/50 flex flex-col items-center justify-center text-center rounded-2xl transition duration-200 opacity-40 hover:opacity-100 hover:border-brand-bright/60 hover:bg-brand-bright/5 focus:outline-none focus-visible:ring-1 focus-visible:ring-brand-bright/50"
                                title="{{ __('multiplayer.invite_friend') }}"
                                aria-label="{{ __('multiplayer.invite_friend') }}">
                                <div
                                    class="w-12 h-12 rounded-full border border-dashed border-border/60 mb-2 flex items-center justify-center font-mono text-lg text-muted transition group-hover:border-brand-bright/60 group-hover:text-brand-bright">
                                    <span class="group-hover:hidden">?</span>
                                    <span class="hidden group-hover:inline leading-none">+</span>
                                </div>
                                <span class="text-xs font-mono text-muted group-hover:text-brand-bright transition">
                                    <span class="group-hover:hidden">{{ __('multiplayer.empty_slot') }}</span>
                                    <span class="hidden group-hover:inline">{{ __('multiplayer.invite_friend') }}</span>
                                </span>
                            </button>
                        @endif
                    @endforeach
                </div>
            </div>

            {{-- Slot meter: one segment per player slot (filled = taken). Replaces a single
                 gold progress bar, which on mobile read as a vague half-filled line -- the
                 segments map 1:1 to the five cards above, so "3 of 5 in" is legible at a glance
                 and lines up with the "joined X/5" count. --}}
            @php $filledSlots = $this->orderedMembers->count(); @endphp
            <div class="flex gap-1.5" aria-hidden="true">
                @for ($s = 0; $s < \App\Livewire\MultiplayerLobby::MAX_PLAYERS; $s++)
                    <div class="h-2 flex-1 rounded-full transition-colors duration-300 {{ $s < $filledSlots ? 'bg-gold' : 'bg-background border border-border/30' }}"></div>
                @endfor
            </div>

            @php
                $playersFull = $this->orderedMembers->count() >= \App\Livewire\MultiplayerLobby::MAX_PLAYERS;
                $spectatorsFull = $this->spectatorCount >= \App\Livewire\MultiplayerLobby::MAX_SPECTATORS;
            @endphp

            <div class="pt-6 border-t border-border/30 space-y-3">
                {{-- Button row: on a phone the actions stack (primary full-width, the two
                     secondary actions share a 2-col row) so they never overflow or wrap into a
                     ragged pile. From `sm` up it's the original one-line layout: primary left,
                     secondary pushed right. Buttons are `w-full sm:w-auto` for that flip. --}}
                <div class="flex flex-col gap-3 sm:flex-row sm:flex-wrap sm:items-center sm:justify-between sm:gap-4">
                    {{-- Primary: role-specific action (Start / Ready / Spectating badge). --}}
                    <div class="sm:flex sm:items-center sm:gap-4">
                        @if ($this->isHost)
                            <button wire:click="startRace" @disabled(!$this->allReady)
                                class="w-full sm:w-auto px-6 py-3 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $this->allReady ? 'bg-gold hover:bg-secondary-7 text-background shadow-md' : 'bg-elevated text-muted cursor-not-allowed border border-border/30' }}">
                                {{ __('multiplayer.start_race') }}
                            </button>
                        @elseif (!$this->isSpectator)
                            @php $meReady = $this->roomData->members->where('user_id', Auth::id())->first()?->is_ready; @endphp
                            <button wire:click="toggleReady"
                                class="w-full sm:w-auto px-6 py-3 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 {{ $meReady ? 'bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5' : 'bg-gold hover:bg-secondary-7 text-background' }}">
                                {{ $meReady ? __('multiplayer.cancel_ready') : __('multiplayer.im_ready') }}
                            </button>
                        @else
                            <span class="w-full sm:w-auto inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl border border-border/40 bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider text-muted">
                                <span class="text-base leading-none">&#128065;</span>{{ __('multiplayer.you_are_spectating') }}
                            </span>
                        @endif
                    </div>

                    {{-- Secondary: role toggle + leave. 2-col grid on mobile, inline row at sm+. --}}
                    <div class="grid grid-cols-2 gap-3 sm:flex sm:flex-wrap sm:items-center sm:gap-4">
                        @if ($this->isSpectator)
                            <button wire:click="toggleSpectator" @disabled($playersFull)
                                class="w-full sm:w-auto px-6 py-3 bg-transparent border border-gold/50 text-gold hover:bg-gold/10 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed"
                                @if ($playersFull) title="{{ __('multiplayer.players_full') }}" @endif>
                                {{ __('multiplayer.become_player') }}
                            </button>
                        @else
                            <button wire:click="toggleSpectator" @disabled($spectatorsFull)
                                class="w-full sm:w-auto px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition disabled:opacity-40 disabled:cursor-not-allowed"
                                @if ($spectatorsFull) title="{{ __('multiplayer.spectators_full') }}" @endif>
                                {{ __('multiplayer.become_spectator') }}
                            </button>
                        @endif

                        <button wire:click="leaveRoom"
                            class="w-full sm:w-auto px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                            {{ __('multiplayer.leave_room') }}
                        </button>
                    </div>
                </div>

                {{-- Status hint row: explains why "Start" isn't active yet. The neutral dot
                     pulses so it reads as a hint, not a button.

                     Three distinct reasons, and they must not be collapsed: "nobody here",
                     "not enough RACERS" and "waiting for ready" look identical from the host's
                     side (a greyed-out button) but need different actions. The middle one is
                     the confusing case a room full of spectators produces -- five people
                     present, still not startable -- so it says so explicitly. --}}
                @if ($this->isHost && !$this->allReady)
                    @php $racerCount = $this->orderedMembers->count(); @endphp
                    {{-- items-start (not items-center) + a small mt on the dot: when the hint
                         wraps to two lines on a phone, the dot stays aligned with the FIRST line
                         instead of floating to the vertical middle. shrink-0 keeps it round. --}}
                    <div class="flex items-start gap-2">
                        <span class="w-1.5 h-1.5 rounded-full bg-muted animate-pulse shrink-0 mt-1.5"></span>
                        <span class="text-xs font-mono text-muted leading-relaxed">
                            @if ($racerCount === 0)
                                {{ __('multiplayer.no_players_to_start') }}
                            @elseif ($racerCount < \App\Livewire\MultiplayerLobby::MIN_PLAYERS_TO_START)
                                {{ __('multiplayer.need_more_players') }}
                            @else
                                {{ __('multiplayer.waiting_ready') }}
                            @endif
                        </span>
                    </div>
                @endif
            </div>

            {{-- Lobby chat: players & spectators can talk while waiting. Stable wire:key so
                 Alpine state (message list) doesn't reset when the lobby re-renders. --}}
            <div wire:key="room-chat-waiting">
                @include('livewire.partials.room-chat', ['currentUserId' => auth()->id()])
            </div>
        </div>
    @endif

    <!-- ===== 3. BATTLE STAGE (TYPERACER MECHANICS) ===== -->
    @if ($this->step === 'racing' && $this->roomData && !$showResultModal)
        {{-- Stable wire:key: Alpine state (raceStarted/countdown/progress) doesn't reset across re-renders. --}}
        {{-- Alpine logic lives in the 'raceArena' component (see @assets), not inline in x-data. --}}
        {{-- Sudden death is synced via WebSocket + a local clock; when it hits 0, lockRace() calls checkSuddenDeath() once. --}}
        {{-- Spectators also render the arena (countdown + racer lanes) but without typing input.
             myId=null tells raceArena to skip all local emit/publish paths. --}}
        @php
            $isSpectator = $this->isSpectator;
            $racers = $this->orderedMembers;
        @endphp
        @php $arenaDense = $racers->count() >= 4; @endphp
        {{-- Hide the chat overlay while the race arena is shown; restore it when this block
             goes away (race finished / result modal / leaving the room). --}}
        <div x-data="{ init() { window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: true } })); }, destroy() { window.dispatchEvent(new CustomEvent('test-activity', { detail: { active: false } })); } }"></div>
        <div wire:key="race-arena-{{ $this->roomCode }}" class="{{ $arenaDense ? 'space-y-4' : 'space-y-6' }}"
            x-data="raceArena({
                myId: @js($isSpectator ? null : Auth::id()),
                isSpectator: @js($isSpectator),
                roomCode: @js($this->roomCode),
                textToType: @js($this->roomData->text_to_type),
                raceStartsAt: @js($this->raceStartsAt),
                raceStartsInMs: @js($this->raceStartsInMs),
                suddenDeathActive: @js($this->suddenDeathActive),
                suddenDeathRemaining: @js($this->suddenDeathRemaining),
                startGraceRemaining: @js($this->startGraceRemaining),
                raceDeadlineRemaining: @js($this->raceDeadlineRemaining),
                resumeProgress: @js($this->myResumeProgress),
            })"
            @keydown.tab.prevent="if (raceStarted && !isFinished && !lockedByTimeout) $refs.typeInput?.focus()">

            <!-- THIN HEADER: ROOM CODE (RIGHT). The sudden-death badge that used to sit on the
                 left was removed: sudden death now shows in ONE place only -- the in-flow banner
                 right above the typing box (below). Same header for racers and spectators, so it
                 is gone for both. -->
            <div class="flex items-center justify-between gap-4">
                {{-- Empty left slot keeps the room code right-aligned under justify-between. The
                     hidden hook is NOT a display -- it only seeds/starts the client sudden-death
                     clock from the server's authoritative remaining the moment the server
                     confirms SD, exactly what the old visible badge's x-init did. Kept so
                     removing the badge does not change WHEN the countdown starts. --}}
                <span>@if ($this->suddenDeathActive)<span x-init="syncSuddenDeath(@js($this->suddenDeathRemaining))" class="hidden"></span>@endif</span>
                <span class="flex items-center gap-3 shrink-0">
                    @if ($this->spectatorCount > 0)
                        <span class="flex items-center gap-1.5 font-mono text-[11px] text-muted">
                            <span class="text-sm leading-none">&#128065;</span>{{ __('multiplayer.spectators_watching', ['count' => $this->spectatorCount]) }}
                        </span>
                    @endif
                    <span class="font-mono text-[11px] uppercase tracking-widest text-muted">
                        {{ __('multiplayer.room_label') }} <span class="text-gold font-bold">- {{ $this->roomCode }}</span>
                    </span>
                </span>
            </div>

            <!-- COUNTDOWN OVERLAY SCREEN: only for race start; the !suddenDeathActive guard keeps it from reappearing during the sudden-death countdown -->
            <template x-if="!raceStarted && !suddenDeathActive">
                <div class="fixed inset-0 bg-background/95 flex flex-col items-center justify-center z-50 select-none">
                    <span class="font-mono text-xs uppercase tracking-[0.4em] text-muted mb-4">{{ __('multiplayer.race_starting') }}</span>
                    <div class="text-fluid-hero font-mono font-black tracking-wider text-gold scale-110 transition-all duration-300"
                        x-text="countdown"></div>
                </div>
            </template>

            @php
                $playerCount = $racers->count();
                $dense = $playerCount >= 4;

                // Each player's initial progress, used by rankOf() to rank players
                // who haven't sent a single WebSocket payload yet. Racers only.
                $laneSeeds = $racers
                    ->mapWithKeys(fn ($m) => [$m->user_id => (int) ($m->progress_percent ?? 0)]);
            @endphp

            <!-- LIVE STANDINGS: ONE LANE PER PLAYER -->
            <div class="border bg-surface/50 border-border/40 rounded-3xl shadow-xl {{ $dense ? 'p-4 space-y-2' : 'p-6 space-y-3' }}">
                <span class="text-xs font-mono uppercase tracking-widest text-muted block">{{ __('multiplayer.live_standings') }}</span>

                {{-- laneSeeds/raceStartMs/textLength are declared once here, then inherited
                     by each lane's x-data. The shared clock is started once too, so each
                     lane's WPM recomputes every second without one timer per player. --}}
                <div x-data="{
                        laneSeeds: @js($laneSeeds),
                        textLength: @js(mb_strlen($this->roomData->text_to_type)),
                        {{-- raceStartsInMs = time remaining per the server at render (negative
                             if the race is already running). Added to Date.now() so the start
                             point sits on the CLIENT's clock -- immune to server-client clock skew.
                             null = race not scheduled yet. --}}
                        raceStartsInMs: @js($this->raceStartsInMs),
                        raceStartMs: null,
                    }"
                    x-init="
                        raceStartMs = raceStartsInMs === null ? null : Date.now() + raceStartsInMs;
                        $store.race.startClock();
                    "
                    class="bg-background/40 rounded-2xl border border-border/20 {{ $dense ? 'p-3 space-y-1' : 'p-4 space-y-1.5' }}">
                    @foreach ($racers as $player)
                        @php $isSelf = ! $isSpectator && $player->user_id === Auth::id(); @endphp
                        {{-- Every lane reads $store.race.opponents[id] uniformly (including yourself via publishLocal),
                             filled from WebSocket payloads without a Livewire re-render. Blade values are only the initial seed. --}}
                        <div x-data="{
                                playerId: @js($player->user_id),
                                seedProgress: @js((int) ($player->progress_percent ?? 0)),
                                seedWpm: @js((int) ($player->wpm ?? 0)),
                                seedFinished: @js((bool) $player->finished_time_seconds),
                                get liveProgress() {
                                    return $store.race.opponents[this.playerId]?.progress ?? this.seedProgress;
                                },
                                /**
                                 * WPM is computed LOCALLY by each viewer rather than waiting
                                 * for the owner's broadcast. An inactive opponent tab is frozen
                                 * by the browser, so an opponent who stops typing would never
                                 * broadcast their decaying WPM -- the number would get stuck.
                                 *
                                 * WPM = (correct chars / 5) / minutes elapsed, and correct
                                 * chars are derived from the progress that IS broadcast.
                                 * Finished players are frozen at their final number.
                                 */
                                get liveWpmValue() {
                                    const reported = $store.race.opponents[this.playerId]?.wpm ?? this.seedWpm;

                                    // Finished player: final WPM is frozen, no longer decays.
                                    // raceStartMs null: race hasn't started, no time elapsed.
                                    if (this.liveFinished || raceStartMs === null) return reported;

                                    // $store.race.now makes this getter recompute every second.
                                    const minutes = ($store.race.now - raceStartMs) / 60000;
                                    if (minutes <= 0) return reported;

                                    // progress_percent is an integer, so correct chars here are
                                    // rounded to ~1% of the text -- every lane (including your own)
                                    // uses the same formula so the numbers stay consistent across screens.
                                    const correctChars = (this.liveProgress / 100) * textLength;

                                    return Math.floor((correctChars / 5) / minutes);
                                },
                                get liveFinished() {
                                    return $store.race.opponents[this.playerId]?.finished ?? this.seedFinished;
                                },
                                get isLeader() {
                                    return String($store.race.leaderId()) === String(this.playerId) && this.liveProgress > 0;
                                },
                                get liveRank() {
                                    return $store.race.rankOf(this.playerId, laneSeeds);
                                },
                                get runnerTilt() {
                                    const w = Math.min(this.liveWpmValue, 120);
                                    return `rotate(${(w / 120) * -8}deg) scale(${1 + (w / 120) * 0.12})`;
                                },
                            }"
                            {{-- Your own lane is fully highlighted (blue card), as in the design.

                                 WRAPS on narrow screens. The three fixed columns (badge + name +
                                 wpm) plus gaps need ~324px, and a 360px phone leaves the lane
                                 barely 250px -- so `flex-1` on the track resolved to ZERO width
                                 and the row still overflowed, clipping the wpm off-screen. The
                                 mascot then sat on top of the finish flag with no visible track
                                 between them, which reads as "the race isn't rendering".
                                 Below `sm` the track therefore takes a full-width line of its
                                 own (order-last), where it has the whole row to move across.
                                 From `sm` up nothing changes: one row, exactly as before. --}}
                            class="flex flex-wrap items-center rounded-xl transition-colors duration-300 sm:flex-nowrap {{ $dense ? 'gap-x-3 gap-y-1 sm:gap-3 py-1.5' : 'gap-x-3 gap-y-1 sm:gap-4 py-2' }} {{ $isSelf ? 'bg-brand/25 border border-gold/70 px-3' : 'border border-transparent px-3' }}">

                            {{-- Live rank. Finished players are marked green
                                 (this design has no separate "FINISHED" badge). --}}
                            <div class="shrink-0 rounded-md border flex items-center justify-center font-mono font-bold transition-colors duration-300 {{ $dense ? 'w-6 h-6 text-[10px]' : 'w-7 h-7 text-xs' }}"
                                :class="liveFinished
                                    ? 'border-active/70 bg-active/15 text-active'
                                    : 'border-gold/70 bg-gold/10 text-gold'"
                                x-text="liveRank"></div>

                            {{-- Name: fixed width from `sm` up so all tracks start at the same x.
                                 On a phone the track is on its own line, so alignment no longer
                                 depends on this and the name may take the space it needs. --}}
                            <div class="flex-1 min-w-0 sm:flex-none flex items-center gap-2 font-mono {{ $dense ? 'sm:w-32' : 'sm:w-40' }}">
                                <span class="truncate {{ $dense ? 'text-xs' : 'text-sm' }} {{ $isSelf ? 'text-foreground font-bold' : 'text-foreground/90' }}">{{ $player->user->username }}</span>
                                @if ($isSelf)
                                    <span class="shrink-0 text-[9px] font-black uppercase tracking-wider px-1.5 py-0.5 rounded bg-gold text-background">{{ __('multiplayer.you') }}</span>
                                @endif
                            </div>

                            {{-- Track: a thin line + the mascot riding it + a finish flag.
                                 The flag lives INSIDE the track (absolute, right), not as a sibling element,
                                 so 100% progress genuinely lands on top of it. --}}
                            @php
                                // Half the mascot width: used as left/right padding on the track so the
                                // mascot (centered on the progress point) isn't clipped at 0% or 100%.
                                $half = $dense ? 12 : 14;
                            @endphp
                            {{-- order-last + basis-full: on a phone this drops onto its own line
                                 BELOW the name/wpm row, so it gets the full width instead of
                                 whatever those columns left over (which was nothing). --}}
                            <div class="relative order-last basis-full sm:order-none sm:basis-auto sm:flex-1 min-w-0 flex items-center {{ $dense ? 'h-8' : 'h-10' }}">

                                {{-- Rail: inset by $half px on left & right so the mascot (centered
                                     on the progress point) isn't clipped at 0% or 100%. --}}
                                <div class="absolute rounded-full bg-elevated {{ $dense ? 'h-1' : 'h-1.5' }}"
                                    style="left: {{ $half }}px; right: {{ $half }}px;"></div>

                                <div class="absolute rounded-full transition-all duration-300 {{ $dense ? 'h-1' : 'h-1.5' }}"
                                    :class="liveFinished ? 'bg-active' : 'bg-gold'"
                                    :style="`left: {{ $half }}px; width: calc((100% - {{ $half * 2 }}px) * ${liveProgress} / 100);`"></div>

                                {{-- Finish flag: right at the rail's right end (the 100% point). --}}
                                <div class="race-finish-flag absolute top-0 bottom-0 -translate-x-1/2 rounded-sm {{ $dense ? 'w-2.5' : 'w-3' }}"
                                    style="left: calc(100% - {{ $half }}px);"
                                    role="img" aria-label="{{ __('multiplayer.finish') }}"></div>

                                {{-- Mascot is centered on the progress point (-translate-x-1/2), so at 100%
                                     its center sits exactly over the flag. z-10 so the flag doesn't cover it. --}}
                                <div class="absolute top-1/2 z-10 -translate-x-1/2 -translate-y-1/2 transition-all duration-300"
                                    :style="`left: calc({{ $half }}px + (100% - {{ $half * 2 }}px) * ${liveProgress} / 100);`">
                                    {{-- Deliberately NOT using <x-friend-avatar>: this container carries Alpine
                                         bindings (:style transform, :class) and has no relative wrapper, whereas
                                         the component always wraps with `relative shrink-0` --
                                         which would break the runner's absolute placement on the track. --}}
                                    <div class="race-runner flex items-center justify-center {{ $dense ? 'w-6 h-6' : 'w-7 h-7' }}"
                                        :style="`transform: ${runnerTilt}`"
                                        :class="{ 'opacity-70': liveProgress === 0 && !liveFinished }">
                                        @if ($player->user->avatar)
                                            <img src="{{ $player->user->avatar }}" alt="{{ $player->user->username }}" referrerpolicy="no-referrer" class="w-full h-full object-cover rounded-md">
                                        @else
                                            <img src="/icon/uetype_mascot.png" alt="{{ $player->user->username }}" class="w-full h-full object-contain">
                                        @endif
                                    </div>
                                </div>
                            </div>

                            {{-- WPM --}}
                            <div class="shrink-0 text-right font-mono {{ $dense ? 'w-14 text-[11px]' : 'w-16 text-xs' }}">
                                <span class="font-bold text-foreground" x-text="liveWpmValue"></span>
                                <span class="text-muted"> wpm</span>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>

            @if (! $isSpectator && ! $hasGivenUp && ! $hasFinished)
                <!-- MAIN TEXT CONTAINER (HIGH-RESPONSIVE TYPERACER-STYLE VISUAL) -->
                {{-- No shake on a typo/refused space: the red highlight on the active word and
                     the input (driven by hasError || justBlocked below) is the sole cue. --}}
                <div class="border bg-surface/40 border-border/40 rounded-3xl shadow-xl {{ $dense ? 'p-5 space-y-4' : 'p-8 space-y-6' }}">
                    {{-- ===== PARAGRAPH: A BOUNDED, SELF-SCROLLING WINDOW =====

                         The whole text used to render at full height. On a phone that is ~14
                         lines, so the input sat far below the fold -- and every keystroke made
                         the browser scroll the focused input back into view. The player could
                         see the words OR the field, never both: read ahead, scroll down, type,
                         get yanked back down, scroll up again. Unplayable in practice even
                         though every keystroke was registering correctly.

                         Now the paragraph is clipped to three lines and slides itself so the
                         word being typed is always on the top visible line, with the next two
                         lines of lookahead under it. The field never moves relative to the
                         text, so there is nothing left to scroll: the same solution the solo
                         engine already uses (typing-engine.md), which is why the height here is
                         also expressed in `em` and follows the line-height rather than a pixel
                         guess.

                         `overflow-hidden` (not `auto`): this must never become a second thing
                         the player has to scroll by hand. --}}
                    <!-- PARAGRAPH DRAFT BLOCK WITH TYPERACER COLOR INDICATORS -->
                    {{-- `wire:ignore` -- THE fix for "the box jumps suddenly" (`berpindah tiba-tiba`).

                         checkInput() calls $wire.updateRaceProgress() on EVERY keystroke (throttled
                         to ~120ms in emitProgress), so every Livewire response morphs this subtree:
                         the arena is morphed ~8x per second while the player types. The server HTML
                         for the track below has NO `style` attribute -- the transform exists only
                         because Alpine evaluated `:style` on the client -- so morphdom diffed
                         server-HTML-without-style against live-DOM-with-transform and STRIPPED the
                         attribute. The paragraph snapped to translateY(0), and since the track
                         carries `transition-transform duration-[85ms]`, it ANIMATED there: the jump.

                         Alpine did not repair it. `:style` is only re-evaluated when its reactive
                         dependency (wordScrollOffset) CHANGES, and between two word advances that
                         value is constant -- so the effect never re-ran and the transform stayed
                         stripped until the next space. Visible only once the offset is non-zero
                         (active word on line 3+), and only when a morph lands between two advances,
                         which is why it was intermittent.

                         Six earlier attempts all changed the FORMULA inside syncWordScroll(). No
                         formula survives the attribute being deleted from the element it writes to.
                         Note the morphs come from our OWN emit -- an OPPONENT's progress arrives via
                         `.race.progress` straight into the Alpine store with no Livewire round-trip
                         (race-echo.js), so other players never morph our DOM.

                         Safe to ignore: nothing in here is server-rendered. The words come from
                         `textToType` (fixed for the whole race) and every state that styles them
                         (currentWordIndex, hasError, justBlocked, wordScrollOffset) lives on the
                         client. It goes on THIS div, not the card above, because the card's padding
                         is $dense-driven and must stay morphable -- these classes are all static.
                         `wire:ignore` blocks morphing, not Alpine teardown, and the root
                         `wire:key="race-arena-..."` is stable, so raceArena is not remounted.

                         Exactly the two-layer fix the solo caret already documents: (1) wire:ignore
                         so Livewire never touches the element, (2) a reactive transform so Alpine
                         always sets it. See typing-engine.blade.php. --}}
                    <div wire:ignore
                        class="font-mono text-xl leading-relaxed tracking-wide select-none p-5 bg-background/30 rounded-xl border border-border/20">
                      {{-- The clipping window. Three lines at leading-relaxed (1.625) = 4.875em,
                           the same figure the solo engine uses, so it follows the line-height
                           instead of a pixel guess that breaks when the type scale changes. --}}
                      <div class="overflow-hidden" style="max-height: 4.875em;">
                      {{-- `relative` makes THIS element the offsetParent of the words, so
                           syncWordScroll() can read each word's offsetTop as its distance from the
                           track top -- pure layout, immune to the translateY transform and to
                           mid-animation timing. Without it offsetParent walked up to a card far
                           above and the paragraph slid off screen. --}}
                      {{-- No `gap-y`: rows stack with NO vertical gap so three lines occupy
                           exactly 4.875em (3 x leading-relaxed), matching the clip window and the
                           solo engine's gapless stride. A row gap would push the third line past
                           the window AND make the line stride disagree with the line-height the
                           scroll math assumes. Word spacing is horizontal only (`gap-x-2`). --}}
                      {{-- `content-start` + an 85ms transition mirror the solo engine's text
                           track: wrapped lines anchor to the top consistently, and the slide is
                           quick enough that no partial "fourth line" lingers visibly in the clip
                           window while the paragraph advances (a slow slide left a half-line
                           crossing the edge long enough to read as a flicker). --}}
                      <div x-ref="wordsTrack"
                        class="relative flex flex-wrap content-start gap-x-2 transition-transform duration-[85ms] ease-out"
                        :style="`transform: translateY(-${wordScrollOffset}px)`">
                        <template x-for="(word, wIdx) in words" :key="wIdx">
                            {{-- Every word carries the SAME box metrics at all times: `px-1`
                                 padding on ALL words, and the active highlight uses `outline`
                                 (drawn OUTSIDE the box, zero layout cost) + a background FILL --
                                 never a `ring`/`border`. Two reasons:

                                 1. Constant metrics: if activation added padding/bold/border that
                                    a resting word lacks, the active word would change WIDTH and
                                    re-wrap the line, making the scroll jump.
                                 2. No stroke at the clip edge: a 1px ring/border on resting words
                                    sat exactly on the window's bottom edge and got clipped
                                    mid-stroke; sub-pixel rounding then made that line flicker
                                    in/out as the paragraph shifted -- the "next line suddenly
                                    appears/disappears" bug. Padding is invisible space (nothing to
                                    clip), a background fill clips cleanly, and an outline is not
                                    part of layout, so none of them flicker at the edge. --}}
                            <span :data-word-index="wIdx"
                                class="px-1 rounded outline-none"
                                {{-- No "passed with an error" state exists any more: word-lock
                                     means a word behind the cursor was necessarily typed
                                     exactly, so every one of them is simply correct. --}}
                                {{-- The active word turns red for a typo AND for a refused
                                     space. Without the second case a rejected space left the
                                     word looking perfectly fine whenever the typed text was a
                                     correct prefix -- nothing on screen said "you are being
                                     stopped here". Note: NO font-bold on the active word -- a
                                     wider glyph run would re-wrap the line just like padding. --}}
                                :class="{
                                    'text-active': wIdx < currentWordIndex,
                                    'text-danger bg-danger/15 outline outline-1 outline-danger/40 underline underline-offset-4 decoration-2': wIdx ===
                                        currentWordIndex && (hasError || justBlocked),
                                    'text-foreground bg-foreground/5 outline outline-1 outline-border/50': wIdx ===
                                        currentWordIndex && !hasError && !justBlocked,
                                    'text-muted': wIdx > currentWordIndex
                                }"
                                x-text="word"></span>
                        </template>
                      </div>{{-- /words track --}}
                      </div>{{-- /clipping window --}}
                    </div>{{-- /paragraph card --}}

                    {{-- SUDDEN-DEATH COUNTDOWN, right above the input where the player is looking.
                         This is now the ONLY place sudden death is shown (the header badge above
                         LIVE STANDINGS was removed). Bound to the Alpine clock so it counts down
                         live; the remaining seconds turn urgent (bigger, brighter) under 5s.

                         `wire:ignore` is load-bearing, for the SAME reason as the paragraph card
                         above: checkInput() morphs this whole subtree ~8x/second, and the server
                         renders this counter with NO text (its value is client-only, via x-text).
                         Without wire:ignore each morph strips the Alpine-rendered seconds and
                         Alpine only rewrites them on the next 1s tick -- so the number visibly
                         FROZE between ticks. wire:ignore blocks morphing, not Alpine, so the
                         countdown updates every second untouched. --}}
                    <div wire:ignore x-show="suddenDeathActive && raceStarted && !isFinished" x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="mb-3 flex items-center justify-center gap-2.5 px-4 py-2.5 rounded-xl bg-danger/10 border border-danger/40">
                        <span class="w-2 h-2 rounded-full bg-danger animate-pulse"></span>
                        <span class="font-mono text-xs font-bold uppercase tracking-widest text-danger">{{ __('multiplayer.sudden_death') }}</span>
                        <span class="font-mono font-black tabular-nums text-danger transition-all duration-200"
                            :class="suddenDeathRemaining <= 5 ? 'text-2xl' : 'text-lg'"
                            x-text="suddenDeathRemaining + 's'"></span>
                    </div>

                    {{-- ===== START-TYPING COUNTDOWN =====
                         Shown only to a racer who has not produced a single percent yet, and only
                         while the grace window is open. This is the visible half of the start
                         deadline: enforcing it silently would have stopped races hanging while
                         leaving the game feeling arbitrary — the original complaint was that
                         nothing gives a player any reason to begin.

                         Gold rather than danger red, and it steps up to red under 5s: at 20
                         seconds out this is a nudge, not a threat, and spending the alarm colour
                         immediately would leave nothing louder for the moment it matters. Sudden
                         death keeps red for itself (see showStartPrompt, which stands down when
                         that banner is up).

                         `wire:ignore` for exactly the reason the banner above carries it: this
                         subtree is morphed ~8x/second and the server renders the counter with no
                         text, so without it the number freezes between 1s ticks. --}}
                    <div wire:ignore x-show="showStartPrompt" x-cloak
                        x-transition:enter="transition ease-out duration-200"
                        x-transition:enter-start="opacity-0 -translate-y-1"
                        x-transition:enter-end="opacity-100 translate-y-0"
                        class="mb-3 flex items-center justify-center gap-2.5 px-4 py-2.5 rounded-xl transition-colors duration-200"
                        :class="startGraceRemaining <= 5 ? 'bg-danger/10 border border-danger/40' : 'bg-gold/10 border border-gold/40'">
                        <span class="w-2 h-2 rounded-full animate-pulse" :class="startGraceRemaining <= 5 ? 'bg-danger' : 'bg-gold'"></span>
                        <span class="font-mono text-xs font-bold uppercase tracking-widest"
                            :class="startGraceRemaining <= 5 ? 'text-danger' : 'text-gold'">{{ __('multiplayer.start_typing_now') }}</span>
                        <span class="font-mono font-black tabular-nums transition-all duration-200"
                            :class="startGraceRemaining <= 5 ? 'text-danger text-2xl' : 'text-gold text-lg'"
                            x-text="startGraceRemaining + 's'"></span>
                    </div>

                    <!-- SINGLE-WORD INPUT FIELD WITH DYNAMIC ERROR HIGHLIGHTING -->
                    <div class="relative">
                        {{-- ===== TOUCH DEVICES =====

                             The four text-correcting attributes are OFF for the same reason as
                             the solo engine, but the consequence here is harsher. Word-lock
                             refuses any character that is not an exact prefix, so a single
                             auto-capitalised letter from a phone keyboard means the word can
                             never even be started -- and it fails silently, which reads as a
                             slow phone rather than a broken feature.

                             `enterkeyhint="next"` is backed by the Enter binding below, so the
                             action key actually does what it says. Deliberately NOT "done" like
                             solo: mid-race that would close the keyboard and cost a tap to
                             resume. It doubles as a second way forward if a keyboard's space
                             behaves oddly.

                             Paste and drop are refused ON PURPOSE. Today a pasted passage is
                             rejected only because it is not a prefix of the target word -- an
                             accident, and one the typed-space path below weakens (its first
                             word IS valid). Losing paste of a single correct word is the
                             intended cost. --}}
                        <input type="text" x-ref="typeInput" x-model="typedText" @input="checkInput()"
                            autocomplete="off" autocorrect="off" autocapitalize="off" spellcheck="false"
                            inputmode="text" enterkeyhint="next"
                            aria-label="{{ __('multiplayer.input_aria') }}"
                            @paste.prevent @drop.prevent
                            @keydown.space="handleSpace($event)"
                            @keydown.enter="handleSpace($event)" :disabled="!raceStarted || isFinished || lockedByTimeout"
                            :placeholder="lockedByTimeout ? @js(__('multiplayer.input_locked')) : (isFinished ? @js(__('multiplayer.input_finished')) : (raceStarted ? @js(__('multiplayer.input_type')) :
                                @js(__('multiplayer.input_wait'))))"
                            {{-- Danger styling covers the refused space too, so the field the
                                 player is looking at reacts as well -- a lone hint line under
                                 the box was too easy to miss mid-race. --}}
                            :class="{
                                'border-danger/60 focus:ring-danger focus:border-danger bg-danger/10 text-danger': hasError || justBlocked,
                                'focus:ring-1 focus:ring-gold focus:border-gold border-border/40 text-foreground': !
                                    hasError && !justBlocked
                            }"
                            class="w-full px-5 py-4 bg-background border rounded-xl font-mono text-base transition-all duration-200 placeholder-muted/60 disabled:opacity-40 disabled:cursor-not-allowed" />

                        {{-- The word-lock rule, surfaced only at the moment the player hits it.
                             Teaching a rule where it bites beats a permanent instruction nobody
                             reads -- and this is the ONLY cue when the typed text is still a
                             correct prefix, because then nothing on screen has turned red. --}}
                        <p x-show="justBlocked" x-cloak
                            x-transition:enter="transition ease-out duration-150"
                            x-transition:enter-start="opacity-0 -translate-y-1"
                            x-transition:enter-end="opacity-100 translate-y-0"
                            class="mt-2 font-mono text-xs text-danger">
                            {{ __('multiplayer.word_must_match') }}
                        </p>
                    </div>

                    <div class="pt-4 flex justify-end">
                        <button type="button" tabindex="-1" wire:click="giveUp"
                            class="px-5 py-2.5 bg-transparent border border-border/40 text-muted hover:text-danger hover:border-danger/40 font-mono text-xs font-bold uppercase tracking-wider rounded-xl transition">
                            {{ __('multiplayer.give_up') }}
                        </button>
                    </div>
                </div>
            @else
                <!-- WAITING SCREEN: SPECTATORS, OR PLAYERS WHO HAVE FINISHED / GIVEN UP -->
                @php
                    $watchTitle = $isSpectator ? __('multiplayer.spectating_title') : ($hasGivenUp ? __('multiplayer.gave_up_title') : __('multiplayer.finished_title'));
                    $watchDesc = $isSpectator ? __('multiplayer.spectating_desc') : ($hasGivenUp ? __('multiplayer.gave_up_waiting') : __('multiplayer.finished_waiting'));
                    $watchColor = $isSpectator ? 'text-gold' : ($hasGivenUp ? 'text-danger' : 'text-active');
                @endphp
                <div class="border bg-surface/40 border-border/40 rounded-3xl shadow-xl p-8 flex flex-col items-center text-center gap-4">
                    <span class="text-fluid-title font-mono font-black uppercase tracking-wider {{ $watchColor }}">
                        {{ $watchTitle }}
                    </span>
                    <p class="text-sm font-mono text-muted max-w-sm">
                        {{ $watchDesc }}
                    </p>

                    {{-- Sudden-death countdown for WATCHERS: spectators (no typing box of their
                         own) plus players who have finished or given up. They lost the racer's
                         banner along with the header badge, so this is where they see the race's
                         sudden-death clock tick down.

                         `x-show="suddenDeathActive"` WITHOUT `!isFinished`: the point here is the
                         race's remaining time, which is exactly what a finished/given-up player is
                         waiting on -- so it shows regardless of the viewer's own finished state.

                         `wire:ignore` for the same reason as the racer's banner: the value is
                         client-only (x-text) and a Livewire morph (a RoomUpdated re-render) would
                         otherwise strip the seconds and freeze the number between 1s ticks. --}}
                    <div wire:ignore x-show="suddenDeathActive" x-cloak
                        class="flex items-center justify-center gap-2.5 px-4 py-2.5 rounded-xl bg-danger/10 border border-danger/40">
                        <span class="w-2 h-2 rounded-full bg-danger animate-pulse"></span>
                        <span class="font-mono text-xs font-bold uppercase tracking-widest text-danger">{{ __('multiplayer.sudden_death') }}</span>
                        <span class="font-mono font-black tabular-nums text-danger transition-all duration-200"
                            :class="suddenDeathRemaining <= 5 ? 'text-2xl' : 'text-lg'"
                            x-text="suddenDeathRemaining + 's'"></span>
                    </div>

                    <button wire:click="leaveRoom"
                        class="mt-2 px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                        {{ __('multiplayer.leave_room') }}
                    </button>
                </div>
            @endif
        </div>
    @endif

    <!-- ===== 4. MATCH RESULT PAGE ===== -->
    @if ($showResultModal && !empty($this->resultSnapshot))
        @php
            $results = collect($this->resultSnapshot)->map(fn ($row) => (object) $row);
            $stillIn = $this->stillInRoomUserIds;

            // Peringkat SELALU dibaca dari kolom `place` yang sudah ditetapkan
            // finalizeRace() -- tidak pernah dari posisi baris. Keduanya dulu dihitung
            // terpisah, jadi layar bisa bilang "peringkat 2" untuk pemain yang riwayat
            // permanennya mencatat juara 1.
            //
            // place null = hasil ditolak anti-cheat. Ia tidak pernah mendapat angka:
            // memberinya nomor urut persis mengembalikan kerusakan yang penolakan itu
            // ada untuk mencegah. Alasannya sudah disampaikan banner myRejectReason
            // (untuk yang bersangkutan) dan badge "tidak dihitung" (untuk semua).
            $rankLabel = function ($place) {
                if (is_null($place)) {
                    return '—';
                }

                $suffix = match ((int) $place) {
                    1 => 'st',
                    2 => 'nd',
                    3 => 'rd',
                    default => 'th',
                };

                return $place . (app()->getLocale() === 'en' ? $suffix : '');
            };
        @endphp
        <div class="space-y-8 sm:space-y-12 animate-fade-in py-4 select-none">

            <!-- MATCH RESULT HEADER -->
            <div class="flex flex-col space-y-1">
                <h1 class="text-fluid-title font-mono font-black text-gold tracking-wider uppercase">{{ __('multiplayer.match_result') }}</h1>
                @php
                    $myRank = $rankLabel($results->firstWhere('user_id', Auth::id())?->place);
                @endphp
                <div class="flex items-center gap-2 text-xs font-mono text-muted uppercase tracking-widest">
                    <span>{{ __('multiplayer.room', ['code' => $this->roomCode]) }}</span>
                    <span class="text-muted/40">-</span>
                    @if ($this->isSpectator)
                        <span class="flex items-center gap-1.5">
                            <span class="text-sm leading-none">&#128065;</span>{{ __('multiplayer.you_spectated') }}
                        </span>
                    @else
                        <span>{!! __('multiplayer.you_placed', ['rank' => '<strong class="text-foreground font-bold">'.e($myRank).'</strong>']) !!}</span>
                    @endif
                </div>

                {{-- Result rejected by server validation: not recorded to stats (average WPM stays intact). --}}
                {{-- The banner shows the SPECIFIC reason (accuracy/WPM/inconsistent/empty), derived --}}
                {{-- server-side in getMyRejectReasonProperty(). Only the player themselves sees the --}}
                {{-- reason; others just see the "not counted" badge in the results table. --}}
                @if ($this->myRejectReason)
                    <div class="mt-2 flex items-center gap-2 rounded-lg border border-danger/40 bg-danger/10 px-3 py-2 text-x-small font-mono text-danger">
                        <svg class="w-4 h-4 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v2m0 4h.01M12 3a9 9 0 100 18 9 9 0 000-18z" />
                        </svg>
                        <span>{{ __($this->myRejectReason) }}</span>
                    </div>
                @endif
            </div>

            <!-- TOP-3 PODIUM VISUAL -->
            {{-- Dipilih lewat kolom `place`, bukan posisi baris: hasil yang ditolak
                 anti-cheat membawa place null, jadi ia tidak bisa lagi berdiri di podium
                 hanya karena WPM curangnya membuatnya terurut paling atas. --}}
            @php
                $rank1 = $results->firstWhere('place', 1);
                $rank2 = $results->firstWhere('place', 2);
                $rank3 = $results->firstWhere('place', 3);
            @endphp
            <div class="grid grid-cols-3 gap-4 items-end max-w-2xl mx-auto pt-16 pb-6 relative">

                <!-- PODIUM 2 (LEFT) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank2)
                        <div class="text-center font-mono text-xs">
                            <span class="text-muted block text-[10px]">{{ $rank2->wpm }} wpm</span>
                            <span
                                class="text-foreground font-bold block truncate max-w-[100px]">{{ $rank2->username }}</span>
                            @if ($rank2->user_id === Auth::id())
                                <span
                                    class="inline-block bg-gold text-background text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <x-friend-avatar :user="$rank2" size="w-10 h-10" ring="border-muted/60" fallback-size="w-4/5 h-4/5" class="race-surge" />
                    @endif
                    <div
                        class="w-full h-20 bg-transparent border-2 border-border/50 rounded-2xl flex items-center justify-center font-mono font-black text-3xl text-muted/40">
                        2
                    </div>
                </div>

                <!-- PODIUM 1 (CENTER) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank1)
                        <div class="text-center font-mono text-xs">
                            <span class="text-muted block text-[10px]">{{ $rank1->wpm }} wpm</span>
                            <span
                                class="text-foreground font-bold block truncate max-w-[120px]">{{ $rank1->username }}</span>
                            @if ($rank1->user_id === Auth::id())
                                <span
                                    class="inline-block bg-background text-gold text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <x-friend-avatar :user="$rank1" size="w-12 h-12" ring="border-gold" fallback-size="w-4/5 h-4/5" class="race-surge shadow-lg" />
                    @endif
                    <div
                        class="w-full h-32 bg-gold rounded-2xl flex items-center justify-center font-mono font-black text-3xl sm:text-5xl text-background shadow-lg">
                        1
                    </div>
                </div>

                <!-- PODIUM 3 (RIGHT) -->
                <div class="flex flex-col items-center space-y-3">
                    @if ($rank3)
                        <div class="text-center font-mono text-xs">
                            <span class="text-muted block text-[10px]">{{ $rank3->wpm }} wpm</span>
                            <span
                                class="text-foreground font-bold block truncate max-w-[100px]">{{ $rank3->username }}</span>
                            @if ($rank3->user_id === Auth::id())
                                <span
                                    class="inline-block bg-gold text-background text-[9px] font-black px-1.5 py-0.2 rounded mt-0.5 scale-90">{{ __('multiplayer.you') }}</span>
                            @endif
                        </div>
                        <x-friend-avatar :user="$rank3" size="w-10 h-10" ring="border-gold/50" fallback-size="w-4/5 h-4/5" class="race-surge" />
                    @endif
                    <div
                        class="w-full h-16 bg-transparent border-2 border-gold/20 rounded-2xl flex items-center justify-center font-mono font-black text-2xl text-gold/30">
                        3
                    </div>
                </div>

            </div>

            <!-- FULL RESULTS TABLE -->
            <div class="space-y-3">
                <span class="text-[11px] font-mono uppercase tracking-[0.25em] text-muted block mb-1">{{ __('multiplayer.full_results') }}</span>
                <div
                    class="w-full border border-border/40 rounded-2xl overflow-x-auto bg-surface/10 backdrop-blur-sm">
                    <table class="w-full text-left font-mono text-sm border-collapse">
                        <thead>
                            <tr
                                class="border-b border-border/30 bg-background/20 text-xs text-muted uppercase tracking-wider">
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_place') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_player') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_wpm') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_accuracy') }}</th>
                                <th class="py-3.5 px-3 sm:px-5 font-medium">{{ __('multiplayer.th_time') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border/20">
                            @foreach ($results as $rank)
                                @php
                                    $isMe = $rank->user_id === Auth::id();
                                    $hasLeft = ! in_array($rank->user_id, $stillIn);
                                @endphp
                                <tr
                                    class="transition duration-150 {{ $isMe ? 'bg-brand/25 text-foreground font-bold' : 'text-muted hover:bg-foreground/[0.02]' }} {{ $hasLeft ? 'opacity-50' : '' }}">
                                    <td class="py-4 px-3 sm:px-5 font-bold text-foreground">{{ $rankLabel($rank->place) }}
                                    </td>
                                    <td class="py-4 px-3 sm:px-5">
                                        <div class="flex items-center gap-2 min-w-0">
                                            <span title="{{ $hasLeft ? __('multiplayer.left_room') : '' }}" class="truncate max-w-[90px] sm:max-w-none">{{ $rank->username }}</span>
                                            @if ($isMe)
                                                <span
                                                    class="bg-brand-bright text-background text-[9px] font-black px-1 py-0.1 rounded uppercase tracking-wide">{{ __('multiplayer.you') }}</span>
                                            @endif
                                            {{-- Anti-cheat: this player's result was rejected (impossible or empty) --}}
                                            {{-- and not recorded to stats. Shown to everyone, not just the affected player. --}}
                                            @if ($rank->result_recorded === false)
                                                <span title="{{ __('multiplayer.result_invalid') }}"
                                                    class="bg-danger/15 text-danger text-[9px] font-black px-1 py-0.1 rounded uppercase tracking-wide">{{ __('multiplayer.not_counted') }}</span>
                                            @endif
                                        </div>
                                    </td>
                                    <td class="py-4 px-3 sm:px-5 text-gold font-bold">{{ $rank->wpm }}</td>
                                    <td class="py-4 px-3 sm:px-5">{{ $rank->accuracy ?? 97.0 }}%</td>
                                    <td class="py-4 px-3 sm:px-5">
                                        @if ($rank->finished_time_seconds && $rank->finished_time_seconds != \App\Models\RoomMember::DNF_SENTINEL_SECONDS)
                                            {{ sprintf('%02d:%02d', floor($rank->finished_time_seconds / 60), $rank->finished_time_seconds % 60) }}
                                        @else
                                            {{-- DNF: don't show a fake time. --}}
                                            <span class="text-danger/70 text-xs">{{ __('multiplayer.dnf') }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>

            {{-- XP PROGRESS REPORT PANEL (real data from getMyXpResultProperty)

                 Shares <x-xp-bar> with the solo result screen. This panel used to draw its own
                 version and had drifted well away from it -- see the component's header for
                 what differed and why the solo styling is the one that survived. --}}
            @php
                $xp = $this->isSpectator ? null : $this->myXpResult;
            @endphp
            @if ($xp && $xp['level'])
                <x-xp-bar :earned="$xp['earned']" :level="$xp['level']" />
            @endif

            {{-- Chat on the result screen: used to invite people to play again. A SEPARATE
                 instance from the lobby chat (different wire:key) -> new Alpine state, so lobby
                 messages don't carry over; consistent with its ephemeral broadcast-only nature. --}}
            <div wire:key="room-chat-result">
                @include('livewire.partials.room-chat', ['currentUserId' => auth()->id()])
            </div>

            <!-- BOTTOM MENU ACTION BUTTONS -->
            {{-- Stack full-width on a phone (no ragged wrap), inline row from sm up. --}}
            <div class="pt-2 flex flex-col sm:flex-row sm:flex-wrap gap-3 sm:gap-4">
                @if ($this->isHost)
                    <button wire:click="playAgain"
                        class="w-full sm:w-auto px-6 py-3 bg-gold hover:bg-secondary-7 text-background font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition duration-200 shadow-md">
                        {{ __('multiplayer.play_again') }}
                    </button>
                @endif
                <button wire:click="leaveRoom"
                    class="w-full sm:w-auto px-6 py-3 bg-transparent border border-border/40 text-muted hover:text-foreground hover:bg-foreground/5 font-mono text-sm font-bold uppercase tracking-wider rounded-xl transition">
                    {{ __('multiplayer.leave_room') }}
                </button>
            </div>

        </div>
    @endif

    {{-- Leave-room confirmation overlay (#3). Shown when an in-room member clicks a nav
         link away from /multiplayer. resources/js/multiplayer-nav.js intercepts the click,
         stashes the destination, and opens this modal via the 'open-modal' event. The
         Confirm button calls window.__mpConfirmLeave() (set by that module) which leaves
         via the server then navigates; Cancel just closes and stays in the room. --}}
    @auth
        <x-modal name="confirm-leave-room" maxWidth="md" focusable>
            <div class="p-5 sm:p-6">
                <h2 class="font-mono text-xl font-semibold leading-tight text-foreground">
                    {{ __('multiplayer.leave_confirm_title') }}
                </h2>
                <p class="mt-2 text-sm leading-6 text-muted">
                    {{ __('multiplayer.leave_confirm_body') }}
                </p>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <button type="button"
                        x-on:click="$dispatch('close-modal', 'confirm-leave-room')"
                        class="rounded-lg px-3 py-1.5 font-mono text-sm text-muted transition-colors duration-150 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                        {{ __('multiplayer.leave_confirm_cancel') }}
                    </button>

                    <button type="button"
                        x-on:click="window.__mpConfirmLeave && window.__mpConfirmLeave()"
                        class="rounded-lg px-3 py-1.5 font-mono text-sm font-medium text-danger transition-colors duration-150 hover:bg-danger/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                        {{ __('multiplayer.leave_confirm_ok') }}
                    </button>
                </div>
            </div>
        </x-modal>
    @endauth

    {{-- ===== ROOM-SWITCH CONFIRMATION =====
         Raised whenever entering another room would abandon the one the player is in --
         through an invite, a typed join code, or Create Room. Driven by the server property
         $pendingRoomSwitch rather than an 'open-modal' event, because the invite path arrives
         on a FRESH PAGE LOAD (/multiplayer?invite=CODE) and the question must already exist
         in the first render; an event dispatched during mount() would have no listener yet.

         The wire:key carries the pending state so Livewire replaces this subtree whenever it
         changes, giving <x-modal> a new Alpine instance seeded with the right `show`. Without
         it the modal would open on a page load but never in-page, since x-data initialises
         once and a morph does not re-run it.

         The old room's code is named explicitly: the player has to know WHICH room they are
         about to give up, not merely be asked whether they are sure. --}}
    @auth
        <div wire:key="room-switch-{{ $pendingRoomSwitch ? ($pendingRoomSwitch['to'] ?? 'create') : 'none' }}">
            <x-modal name="confirm-room-switch" maxWidth="md" :show="$pendingRoomSwitch !== null" focusable>
                <div class="p-5 sm:p-6">
                    <h2 class="font-mono text-xl font-semibold leading-tight text-foreground">
                        {{ __('multiplayer.switch_confirm_title') }}
                    </h2>
                    <p class="mt-2 text-sm leading-6 text-muted">
                        @if ($pendingRoomSwitch && $pendingRoomSwitch['to'])
                            {{ __('multiplayer.switch_confirm_body', [
                                'from' => $pendingRoomSwitch['from'],
                                'to' => $pendingRoomSwitch['to'],
                            ]) }}
                        @else
                            {{ __('multiplayer.switch_confirm_body_create', [
                                'from' => $pendingRoomSwitch['from'] ?? '',
                            ]) }}
                        @endif
                    </p>

                    <div class="mt-6 flex items-center justify-end gap-2">
                        <button type="button" wire:click="cancelRoomSwitch"
                            class="rounded-lg px-3 py-1.5 font-mono text-sm text-muted transition-colors duration-150 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                            {{ __('multiplayer.switch_confirm_cancel') }}
                        </button>

                        <button type="button" wire:click="confirmRoomSwitch"
                            class="rounded-lg px-3 py-1.5 font-mono text-sm font-medium text-danger transition-colors duration-150 hover:bg-danger/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                            {{ __('multiplayer.switch_confirm_ok') }}
                        </button>
                    </div>
                </div>
            </x-modal>
        </div>
    @endauth

    {{-- Host kick confirmation overlay. Opened by askKick(id, name) from a kick button
         (player card or spectator list), which stashes the target in Alpine state. The
         Confirm button calls confirmKick() -> $wire.kickMember(kickId). Same x-modal
         component as the leave/sign-out overlays. --}}
    @auth
        <x-modal name="confirm-kick-member" maxWidth="md" focusable>
            <div class="p-5 sm:p-6">
                <h2 class="font-mono text-xl font-semibold leading-tight text-foreground">
                    {{ __('multiplayer.kick_confirm_title') }}
                </h2>
                <p class="mt-2 text-sm leading-6 text-muted">
                    {{-- :name resolved client-side from the stashed target. --}}
                    <span x-text="@js(__('multiplayer.kick_confirm_body', ['name' => '__NAME__'])).replace('__NAME__', kickName)"></span>
                </p>

                <div class="mt-6 flex items-center justify-end gap-2">
                    <button type="button"
                        x-on:click="$dispatch('close-modal', 'confirm-kick-member')"
                        class="rounded-lg px-3 py-1.5 font-mono text-sm text-muted transition-colors duration-150 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                        {{ __('multiplayer.kick_confirm_cancel') }}
                    </button>

                    <button type="button"
                        x-on:click="confirmKick()"
                        class="rounded-lg px-3 py-1.5 font-mono text-sm font-medium text-danger transition-colors duration-150 hover:bg-danger/10 focus:outline-none focus-visible:ring-2 focus-visible:ring-danger focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                        {{ __('multiplayer.kick_confirm_ok') }}
                    </button>
                </div>
            </div>
        </x-modal>
    @endauth

    {{-- Invite-a-friend overlay. Opened by clicking an empty player slot (openInvite()).
         Lists the caller's accepted friends: online ones get an Invite button (which fires
         $wire.invitePlayer and flips to "Invited"), offline ones are shown but disabled.
         The invited friend receives a real-time toast with a join link (see toasts.js). --}}
    @auth
        @if ($this->step === 'waiting')
            <x-modal name="invite-friends" maxWidth="md" focusable>
                <div class="p-5 sm:p-6">
                    <h2 class="font-mono text-xl font-semibold leading-tight text-foreground">
                        {{ __('multiplayer.invite_title') }}
                    </h2>
                    <p class="mt-1 text-sm leading-6 text-muted">{{ __('multiplayer.invite_subtitle') }}</p>

                    <div class="mt-5 max-h-80 overflow-y-auto -mx-1 px-1 space-y-2">
                        @forelse ($this->invitableFriends as $row)
                            @php $friend = $row['user']; @endphp
                            <div class="flex items-center gap-3 p-2.5 rounded-xl border border-border/40 bg-surface/40">
                                <div class="relative shrink-0">
                                    <x-friend-avatar :user="$friend" size="w-10 h-10" shape="rounded-lg"
                                        bg="bg-foreground/5" :bordered="false" fallback-size="w-3/5 h-3/5" />
                                    {{-- Presence dot. --}}
                                    <span class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full border-2 border-background {{ $row['online'] ? 'bg-active' : 'bg-muted' }}"></span>
                                </div>

                                <div class="min-w-0 flex-1">
                                    <p class="font-mono text-sm font-bold text-foreground truncate">{{ $friend->username }}</p>
                                    {{-- "In another room" outranks plain online: it is the fact that
                                         changes what inviting them MEANS. Accepting now costs them
                                         whatever they are in the middle of, and the picker used to
                                         look identical either way. --}}
                                    <p class="font-mono text-[11px] uppercase tracking-wider {{ $row['busy'] && ! $row['in_room'] ? 'text-gold' : ($row['online'] ? 'text-active' : 'text-muted') }}">
                                        @if ($row['busy'] && ! $row['in_room'])
                                            {{ __('multiplayer.invite_busy') }}
                                        @else
                                            {{ $row['online'] ? __('multiplayer.invite_online') : __('multiplayer.invite_offline') }}
                                        @endif
                                    </p>
                                </div>

                                @if ($row['in_room'])
                                    <span class="shrink-0 px-3 py-1.5 font-mono text-[11px] font-bold uppercase tracking-wider text-muted">
                                        {{ __('multiplayer.invite_in_room') }}
                                    </span>
                                @elseif ($row['online'])
                                    {{-- During the post-invite cooldown the button is disabled and
                                         counts down (e.g. "5s"); at 0 it becomes invitable again. --}}
                                    <button type="button"
                                        x-on:click="invite({{ $friend->id }})"
                                        x-bind:disabled="inviteRemaining({{ $friend->id }}) > 0"
                                        class="shrink-0 min-w-[5.5rem] text-center px-4 py-1.5 font-mono text-[11px] font-bold uppercase tracking-wider rounded-lg border transition disabled:cursor-default"
                                        x-bind:class="inviteRemaining({{ $friend->id }}) > 0
                                            ? 'border-active/40 text-active bg-active/10'
                                            : 'border-brand-bright/50 text-brand-bright hover:bg-brand-bright/10'">
                                        <span x-show="inviteRemaining({{ $friend->id }}) === 0">{{ __('multiplayer.invite_action') }}</span>
                                        <span x-show="inviteRemaining({{ $friend->id }}) > 0" x-cloak>
                                            <span x-text="inviteRemaining({{ $friend->id }})"></span>s
                                        </span>
                                    </button>
                                @else
                                    <span class="shrink-0 px-4 py-1.5 font-mono text-[11px] font-bold uppercase tracking-wider text-muted/60 border border-border/30 rounded-lg cursor-not-allowed"
                                        title="{{ __('multiplayer.invite_offline_hint') }}">
                                        {{ __('multiplayer.invite_action') }}
                                    </span>
                                @endif
                            </div>
                        @empty
                            <div class="py-8 text-center">
                                <p class="font-mono text-sm text-muted">{{ __('multiplayer.invite_no_friends') }}</p>
                                <a href="{{ route('friends.index') }}" class="mt-2 inline-block font-mono text-xs text-brand-bright hover:underline">
                                    {{ __('multiplayer.invite_find_friends') }}
                                </a>
                            </div>
                        @endforelse
                    </div>

                    <div class="mt-6 flex items-center justify-end">
                        <button type="button"
                            x-on:click="$dispatch('close-modal', 'invite-friends')"
                            class="rounded-lg px-3 py-1.5 font-mono text-sm text-muted transition-colors duration-150 hover:text-foreground focus:outline-none focus-visible:ring-2 focus-visible:ring-brand focus-visible:ring-offset-2 focus-visible:ring-offset-background">
                            {{ __('multiplayer.close') }}
                        </button>
                    </div>
                </div>
            </x-modal>
        @endif
    @endauth

    {{-- Race arena logic lives in resources/js/race-arena.js (Alpine 'race' store
         + 'raceArena' component) and resources/js/race-echo.js (Echo subscription),
         both bundled via app.js. Server data still comes in through
         @js(...) in the markup above, not through the modules. --}}
</div>
