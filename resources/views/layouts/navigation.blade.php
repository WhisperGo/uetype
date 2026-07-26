{{-- Top navigation bar: primary links, leaderboard shortcut, account dropdown, and responsive mobile menu. --}}
@php
    // Single source for desktop & mobile -- see App\Support\NavItems.
    $navMain = App\Support\NavItems::main();
    $navAccount = App\Support\NavItems::account();

    // Leaderboard is shown separately (trophy icon on the right on desktop), so it's
    // split out from the left group -- but the data source stays the same.
    $navLeaderboard = collect($navMain)->firstWhere('key', 'leaderboard');
    $navPrimary = collect($navMain)->reject(fn ($i) => $i['key'] === 'leaderboard');

    // Pending incoming friend requests -> nav badge. Server-rendered here for an
    // accurate baseline on load/navigate; the Alpine root below refreshes it live.
    $pendingFriendRequests = Auth::check()
        ? App\Models\Friendship::where('addressee_id', Auth::id())
            ->where('status', App\Enums\FriendshipStatus::Pending)
            ->count()
        : 0;
@endphp

<nav x-data="navBadges({{ $pendingFriendRequests }})" class="{{ request()->is('typing') || request()->is('/') ? '' : 'sticky top-0' }} z-40 border-b border-white/5 bg-background/80 backdrop-blur-md">
    <div class="px-4 mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo / Wordmark -->
                <div class="flex items-center shrink-0">
                    <a href="{{ route('home') }}" class="flex items-center gap-3">
                        <x-application-logo class="block w-auto h-12" />
                        <span class="font-display text-lg text-gold leading-none pt-1">UETYPE</span>
                    </a>
                </div>

                {{-- Navigation Links.
                     `sm:items-center` is load-bearing: flex items default to `stretch`, so
                     without it every link grows to the full 64px bar height and picks up
                     ~23px of invisible clickable space above and below its label. The old
                     `sm:-my-px` was a Breeze leftover for an active `border-b-2` that no
                     longer exists. `gap-2` moves 4px out of the links' padding (clickable)
                     and into the gap (not clickable) -- same visual spacing, smaller targets. --}}
                <div class="hidden sm:ms-10 sm:flex sm:items-center sm:gap-2">
                    @foreach ($navPrimary as $item)
                        <x-nav-link href="{{ $item['href'] }}" :active="$item['active']">
                            {{ __($item['label']) }}
                        </x-nav-link>
                    @endforeach
                </div>
            </div>

            <!-- Right side -->
            <div class="hidden sm:flex sm:items-center sm:gap-4 sm:ms-6">
                @auth
                    <!-- Trophy shortcut -> Leaderboard -->
                    <a href="{{ $navLeaderboard['href'] }}" title="{{ __($navLeaderboard['label']) }}"
                        class="p-2 rounded-lg border {{ $navLeaderboard['active'] ? 'text-gold border-gold/40 bg-gold/10' : 'text-muted border-transparent hover:text-gold hover:border-gold/30 hover:bg-surface' }} focus:outline-none focus-visible:ring-1 focus-visible:ring-gold/40 transition">
                        <x-icon-trophy />
                    </a>

                    <div class="h-6 w-px bg-border"></div>
                @endauth

                @auth
                    {{-- Endpoint URL for the live friend-request badge (see nav-badges.js). --}}
                    <script>window.__friendPendingCountUrl = @js(route('friends.pending-count'));</script>
                @endauth

                <!-- Settings Dropdown -->
                <x-dropdown align="right" width="w-56">
                    <x-slot name="trigger">
                        <button
                            class="relative inline-flex items-center gap-2.5 px-2 py-1.5 leading-tight transition rounded-lg hover:bg-surface focus:outline-none focus-visible:ring-1 focus-visible:ring-border">
                            @auth
                                <span class="relative shrink-0">
                                    @if (Auth::user()->avatar)
                                        <img src="{{ Auth::user()->avatar }}" alt="{{ Auth::user()->username }}"
                                            class="object-cover rounded-lg w-9 h-9 border-2 border-gold/70 shadow-sm"
                                            referrerpolicy="no-referrer">
                                    @else
                                        <span
                                            class="flex items-center justify-center text-sm font-bold uppercase rounded-lg w-9 h-9 border-2 border-gold/70 bg-gradient-to-br from-brand to-gold text-background">
                                            {{ Str::substr(Auth::user()->username, 0, 1) }}
                                        </span>
                                    @endif
                                    {{-- Friend-request indicator: small gold dot on the avatar, --}}
                                    {{-- visible without opening the menu. Ring matches the nav background --}}
                                    {{-- so the dot reads as a badge, not part of the avatar art. --}}
                                    <span x-show="friendRequests > 0" x-cloak
                                        class="absolute -top-1 -right-1 w-3 h-3 rounded-full bg-gold ring-2 ring-background"
                                        :aria-label="friendRequests + ' {{ __('nav.friend_requests_pending') }}'"></span>
                                </span>

                                <span class="flex flex-col items-start font-mono">
                                    <span class="text-sm font-bold text-foreground leading-tight">{{ Auth::user()->username }}</span>
                                    <span class="text-xs leading-tight transition-colors" :class="open ? 'text-gold' : 'text-muted'">lv. {{ Auth::user()->levelData()['level'] }}</span>
                                </span>
                            @else
                                <span class="font-medium text-sm text-foreground px-1">{{ __('common.guest') }}</span>
                            @endauth

                            <svg class="w-4 h-4 fill-current text-muted" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                    clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @auth
                            @foreach ($navAccount as $item)
                                <x-dropdown-link :href="$item['href']">
                                    <x-dynamic-component :component="$item['icon']"
                                        class="h-4 w-4 shrink-0 text-muted/70 transition group-hover:text-gold" />
                                    {{ __($item['label']) }}
                                    {{-- Same gold friend-request dot next to the Friends item. --}}
                                    @if ($item['key'] === 'friends')
                                        <span x-show="friendRequests > 0" x-cloak
                                            class="w-2 h-2 rounded-full bg-gold shrink-0"></span>
                                    @endif
                                </x-dropdown-link>
                            @endforeach

                            <div class="my-1 border-t border-white/5"></div>

                            {{-- Sign Out turns danger-red on hover -- label and glyph together.
                                 Colouring only the 16px icon left the larger, faster-read label
                                 saying the opposite (`hover:text-foreground`), and a lone hue on a
                                 glyph that small is easy to miss under red-green colour deficiency. --}}
                            <button type="button"
                                x-on:click="$dispatch('open-modal', 'confirm-sign-out')"
                                class="group flex w-full items-center gap-2 px-4 py-2 text-start text-sm leading-5 text-muted transition duration-150 ease-in-out hover:bg-elevated hover:text-danger focus:bg-elevated focus:text-danger focus:outline-none">
                                <x-icon-logout class="h-4 w-4 shrink-0 text-muted/70 transition group-hover:text-danger group-focus:text-danger" />
                                <span>{{ __('nav.logout') }}</span>
                            </button>
                        @else
                            <x-dropdown-link :href="route('login')">{{ __('nav.login') }}</x-dropdown-link>

                            <div class="my-1 border-t border-white/5"></div>
                            <p class="px-4 pt-1 pb-1 text-[0.6rem] font-mono uppercase tracking-wider text-muted/60">{{ __('settings.language.label') }}</p>
                            @foreach (App\Support\Locale::labels() as $code => $label)
                                <form method="POST" action="{{ route('locale.update') }}">
                                    @csrf
                                    <input type="hidden" name="locale" value="{{ $code }}">
                                    <button type="submit"
                                        class="flex items-center justify-between w-full px-4 py-2 text-sm text-start transition {{ app()->getLocale() === $code ? 'text-brand-bright' : 'text-muted hover:bg-elevated hover:text-foreground' }}">
                                        {{ $label }}
                                        @if (app()->getLocale() === $code)
                                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" /></svg>
                                        @endif
                                    </button>
                                </form>
                            @endforeach
                        @endauth
                    </x-slot>
                </x-dropdown>
            </div>

            <!-- Hamburger -->
            <div class="flex items-center -me-2 sm:hidden">
                <button @click="open = ! open"
                    class="inline-flex items-center justify-center p-2 transition rounded-md text-muted hover:text-foreground hover:bg-surface focus:outline-none">
                    <svg class="w-6 h-6" stroke="currentColor" fill="none" viewBox="0 0 24 24">
                        <path :class="{ 'hidden': open, 'inline-flex': !open }" class="inline-flex"
                            stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                            d="M4 6h16M4 12h16M4 18h16" />
                        <path :class="{ 'hidden': !open, 'inline-flex': open }" class="hidden" stroke-linecap="round"
                            stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            </div>
        </div>
    </div>

    <!-- Responsive Navigation Menu -->
    <div :class="{ 'block': open, 'hidden': !open }" class="hidden border-t sm:hidden border-white/5">
        <div class="pt-2 pb-3 space-y-1">
            @foreach ($navPrimary as $item)
                <x-responsive-nav-link href="{{ $item['href'] }}"
                    :active="$item['active']">{{ __($item['label']) }}</x-responsive-nav-link>
            @endforeach
            @auth
                <x-responsive-nav-link href="{{ $navLeaderboard['href'] }}" :active="$navLeaderboard['active']">
                    <span class="flex items-center gap-2">
                        <x-icon-trophy class="w-4 h-4 text-gold" />
                        {{ __($navLeaderboard['label']) }}
                    </span>
                </x-responsive-nav-link>
            @endauth
        </div>

        <div class="pt-4 pb-1 border-t border-white/5">
            @auth
                {{-- Username and level only, matching the desktop dropdown. The email
                     belongs to Settings and your own profile, not a nav panel that opens
                     over the shoulder of anyone nearby. --}}
                <div class="px-4">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-medium text-foreground">{{ Auth::user()->username }}</span>
                        <span class="font-mono text-xs text-muted">lv. {{ Auth::user()->levelData()['level'] }}</span>
                    </div>
                </div>
                <div class="mt-3 space-y-1">
                    @foreach ($navAccount as $item)
                        <x-responsive-nav-link :href="$item['href']" :active="$item['active']">
                            <span class="inline-flex items-center gap-2">
                                {{-- On an active row the icon inherits brand-bright from the link
                                     itself, so only the resting/hover colours are stated here. --}}
                                <x-dynamic-component :component="$item['icon']"
                                    class="h-4 w-4 shrink-0 transition {{ $item['active'] ? '' : 'text-muted/70 group-hover:text-gold' }}" />
                                {{ __($item['label']) }}
                                @if ($item['key'] === 'friends')
                                    <span x-show="friendRequests > 0" x-cloak
                                        class="w-2 h-2 rounded-full bg-gold shrink-0"></span>
                                @endif
                            </span>
                        </x-responsive-nav-link>
                    @endforeach
                    <button type="button"
                        x-on:click="$dispatch('open-modal', 'confirm-sign-out'); open = false"
                        class="group flex w-full items-center gap-2 border-l-4 border-transparent py-2 ps-3 pe-4 text-start text-base font-medium text-muted transition duration-150 ease-in-out hover:border-white/20 hover:bg-surface hover:text-danger focus:border-white/20 focus:bg-surface focus:text-danger focus:outline-none">
                        <x-icon-logout class="h-4 w-4 shrink-0 text-muted/70 transition group-hover:text-danger group-focus:text-danger" />
                        <span>{{ __('nav.logout') }}</span>
                    </button>
                </div>
            @else
                <div class="px-4">
                    <div class="text-base font-medium text-foreground">{{ __('common.guest') }}</div>
                </div>
                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('login')">{{ __('nav.login') }}</x-responsive-nav-link>
                </div>
                <div class="px-4 mt-4">
                    <p class="text-[0.6rem] font-mono uppercase tracking-wider text-muted/60 mb-1">{{ __('settings.language.label') }}</p>
                    <div class="flex gap-2">
                        @foreach (App\Support\Locale::labels() as $code => $label)
                            <form method="POST" action="{{ route('locale.update') }}" class="flex-1">
                                @csrf
                                <input type="hidden" name="locale" value="{{ $code }}">
                                <button type="submit"
                                    class="w-full px-3 py-2 font-mono text-xs text-center transition border rounded-lg {{ app()->getLocale() === $code ? 'border-brand bg-brand/10 text-foreground' : 'border-white/10 text-muted hover:text-foreground' }}">
                                    {{ strtoupper($code) }}
                                </button>
                            </form>
                        @endforeach
                    </div>
                </div>
            @endauth
        </div>
    </div>

</nav>
