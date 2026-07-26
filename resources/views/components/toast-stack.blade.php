{{-- ===== GLOBAL TOAST =====
     ONE container for all notifications (friends, clan, chat). Previously three
     separate components rendered three containers at the exact same coordinates,
     so toasts arriving at once overlapped.

     Shows only ONE toast at a time (bottom-right): a new notification replaces the
     previous one instead of piling upward -- see push() in resources/js/toasts.js.

     Applies on ALL pages: a player may be typing or in multiplayer when a
     notification arrives. Kept outside {{ '{{ $slot }}' }} so it survives across
     wire:navigate. Logic lives in resources/js/toasts.js. --}}
@auth
    <div x-data="toastStack(@js([
        'userId' => Auth::id(),
        'clanId' => Auth::user()->clan?->id,
        'routes' => [
            'friends' => route('friends.index'),
            'clans' => route('clans.index'),
            'clanWar' => route('clan-war.index'),
            'chat' => route('chat.index'),
        ],
        'labels' => [
            'friend' => [
                'accepted' => __('notif.friend.accepted'),
                'request' => __('notif.friend.request'),
            ],
            'clan' => [
                'accepted' => __('notif.clan.accepted'),
                'war-result' => __('notif.clan.war_result'),
                'war-challenge' => __('notif.clan.war_challenge'),
                'war-accepted' => __('notif.clan.war_accepted'),
                'war-declined' => __('notif.clan.war_declined'),
                'update' => __('notif.clan.update'),
            ],
        ],
    ]))"
        class="fixed z-[60] bottom-5 right-5 flex flex-col gap-3 w-80 max-w-[calc(100vw-2.5rem)] pointer-events-none">
        <template x-for="t in toasts" :key="t.id">
            <div x-transition:enter="transition ease-out duration-300"
                x-transition:enter-start="opacity-0 translate-y-4"
                x-transition:enter-end="opacity-100 translate-y-0"
                x-transition:leave="transition ease-in duration-200"
                x-transition:leave-start="opacity-100 translate-x-0"
                x-transition:leave-end="opacity-0 translate-x-4"
                class="pointer-events-auto flex items-start gap-3 p-4 rounded-2xl border shadow-lg bg-surface border-white/10 backdrop-blur">
                <div class="w-9 h-9 rounded-lg flex items-center justify-center shrink-0"
                    :class="{
                        'bg-gold/15 text-gold': t.tone === 'gold',
                        'bg-danger/15 text-danger': t.tone === 'danger',
                        'bg-brand/15 text-brand-bright': t.tone === 'brand',
                    }">
                    {{-- Accepted (friend / clan join / war) --}}
                    <template x-if="t.icon === 'check'">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                    </template>
                    {{-- Declined --}}
                    <template x-if="t.icon === 'x'">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                    </template>
                    {{-- Incoming war challenge: crossed swords --}}
                    <template x-if="t.icon === 'swords'">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M14.5 17.5L3 6V3h3l11.5 11.5M13 19l6-6M16 16l4 4M19 21l2-2" /></svg>
                    </template>
                    {{-- Incoming friend request --}}
                    <template x-if="t.icon === 'user-plus'">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M18 9v3m0 0v3m0-3h3m-3 0h-3m-2-5a4 4 0 11-8 0 4 4 0 018 0zM3 20a6 6 0 0112 0v1H3v-1z" /></svg>
                    </template>
                    {{-- Clan join request / general update --}}
                    <template x-if="t.icon === 'group'">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M17 20h5v-1a4 4 0 00-3-3.87M9 20H4v-1a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6-1a4 4 0 10-4-4" /></svg>
                    </template>
                    {{-- Achievement unlocked (queued by the solo result page, not Echo) --}}
                    <template x-if="t.icon === 'star'">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M11.48 3.5a.56.56 0 011.04 0l2.13 4.32 4.77.69c.46.07.64.63.31.95l-3.45 3.36.81 4.75c.08.46-.4.81-.81.59L12 15.9l-4.27 2.25c-.41.22-.89-.13-.81-.59l.81-4.75-3.45-3.36a.56.56 0 01.31-.95l4.77-.69L11.48 3.5z" /></svg>
                    </template>
                    {{-- Incoming message --}}
                    <template x-if="t.icon === 'chat'">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8-1.17 0-2.29-.2-3.32-.56L3 21l1.56-4.68C3.57 15.19 3 13.65 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z" /></svg>
                    </template>
                </div>
                <div class="min-w-0 flex-1">
                    <p class="font-mono text-xs uppercase tracking-wider text-muted" x-text="t.title"></p>
                    <p class="mt-0.5 font-mono text-sm text-foreground break-words" x-text="t.message"></p>
                    <a :href="t.href" class="mt-1.5 inline-block font-mono text-xs text-brand-bright hover:underline">{{ __('notif.view') }}</a>
                </div>
                <button @click="dismiss(t.id)" class="text-muted hover:text-foreground shrink-0" aria-label="{{ __('notif.dismiss') }}">
                    <svg class="w-4 h-4" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2"><path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" /></svg>
                </button>
            </div>
        </template>
    </div>
@endauth
