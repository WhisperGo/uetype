<nav x-data="{ open: false }" class="sticky top-0 z-40 border-b border-white/5 bg-background/80 backdrop-blur-md">
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

                <!-- Navigation Links -->
                <div class="hidden sm:-my-px sm:ms-10 sm:flex sm:gap-1">
                    <x-nav-link href="{{ url('/typing') }}" :active="request()->is('typing')">
                        {{ __('nav.solo') }}
                    </x-nav-link>
                    <x-nav-link href="{{ url('/multiplayer') }}" :active="request()->is('multiplayer')">
                        {{ __('nav.multiplayer') }}
                    </x-nav-link>
                    <x-nav-link href="{{ route('clans.index') }}" :active="request()->routeIs('clans.index', 'clan-war.index')">
                        {{ __('nav.klan') }}
                    </x-nav-link>
                </div>
            </div>

            <!-- Right side -->
            <div class="hidden sm:flex sm:items-center sm:gap-4 sm:ms-6">
                @auth
                    <!-- Trophy shortcut -> Leaderboard -->
                    <a href="{{ route('leaderboard') }}" title="{{ __('nav.leaderboard') }}"
                        class="p-2 rounded-lg border {{ request()->is('leaderboard') ? 'text-gold border-gold/40 bg-gold/10' : 'text-muted border-transparent hover:text-gold hover:border-gold/30 hover:bg-surface' }} focus:outline-none focus-visible:ring-1 focus-visible:ring-gold/40 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                    </a>

                    <div class="h-6 w-px bg-border"></div>
                @endauth

                <!-- Settings Dropdown -->
                <x-dropdown align="right" width="w-56">
                    <x-slot name="trigger">
                        <button
                            class="inline-flex items-center gap-2.5 px-2 py-1.5 leading-tight transition rounded-lg hover:bg-surface focus:outline-none focus-visible:ring-1 focus-visible:ring-border">
                            @auth
                                @if (Auth::user()->avatar)
                                    <img src="{{ Auth::user()->avatar }}" alt="{{ Auth::user()->username }}"
                                        class="object-cover rounded-lg w-9 h-9 border-2 border-gold/70 shadow-sm shrink-0"
                                        referrerpolicy="no-referrer">
                                @else
                                    <span
                                        class="flex items-center justify-center text-sm font-bold uppercase rounded-lg w-9 h-9 border-2 border-gold/70 bg-gradient-to-br from-brand to-gold text-background shrink-0">
                                        {{ Str::substr(Auth::user()->username, 0, 1) }}
                                    </span>
                                @endif

                                <span class="flex flex-col items-start font-mono">
                                    <span class="text-sm font-bold text-foreground leading-tight">{{ Auth::user()->username }}</span>
                                    <span class="text-xs text-muted leading-tight">lv. {{ Auth::user()->levelData()['level'] }}</span>
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
                            <x-dropdown-link :href="route('profile.edit')">{{ __('nav.profile') }}</x-dropdown-link>

                            <x-dropdown-link :href="route('achievements.index')">{{ __('nav.achievements') }}</x-dropdown-link>

                            <span class="flex items-center justify-between w-full px-4 py-2 text-sm text-muted/50 cursor-not-allowed" title="{{ __('common.coming_soon') }}">
                                {{ __('nav.user_stats') }}
                                <span class="text-[0.6rem] font-mono uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">{{ __('common.soon') }}</span>
                            </span>
                            <x-dropdown-link :href="route('friends.index')">{{ __('nav.friends') }}</x-dropdown-link>
                            <x-dropdown-link :href="route('settings')">{{ __('nav.settings') }}</x-dropdown-link>

                            <div class="my-1 border-t border-white/5"></div>

                            <button type="button"
                                x-on:click="$dispatch('open-modal', 'confirm-sign-out')"
                                class="group flex w-full items-center gap-2 px-4 py-2 text-start text-sm leading-5 text-muted transition duration-150 ease-in-out hover:bg-elevated hover:text-foreground focus:bg-elevated focus:text-foreground focus:outline-none">
                                <svg class="h-4 w-4 text-muted/70 transition group-hover:text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round"
                                        d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                                </svg>
                                <span>{{ __('nav.logout') }}</span>
                            </button>
                        @else
                            <x-dropdown-link :href="route('login')">{{ __('nav.login') }}</x-dropdown-link>
                            <x-dropdown-link :href="route('register')">{{ __('nav.register') }}</x-dropdown-link>

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
            <x-responsive-nav-link href="{{ url('/typing') }}"
                :active="request()->is('typing')">{{ __('nav.solo') }}</x-responsive-nav-link>
            <x-responsive-nav-link href="{{ url('/multiplayer') }}"
                :active="request()->is('multiplayer')">{{ __('nav.multiplayer') }}</x-responsive-nav-link>
            {{-- <span
                class="flex items-center w-full gap-2 py-2 text-base font-medium cursor-not-allowed ps-3 pe-4 text-muted/50">
                {{ __('nav.multiplayer') }}
                <span
                    class="text-[0.6rem] font-mono uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">soon</span>
            </span> --}}
            <x-responsive-nav-link href="{{ route('clans.index') }}"
                :active="request()->routeIs('clans.index', 'clan-war.index')">{{ __('nav.klan') }}</x-responsive-nav-link>
            @auth
                <x-responsive-nav-link href="{{ url('/leaderboard') }}" :active="request()->is('leaderboard')">
                    <span class="flex items-center gap-2">
                        <svg class="w-4 h-4 text-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M3 13.125C3 12.504 3.504 12 4.125 12h2.25c.621 0 1.125.504 1.125 1.125v6.75C7.5 20.496 6.996 21 6.375 21h-2.25A1.125 1.125 0 0 1 3 19.875v-6.75ZM9.75 8.625c0-.621.504-1.125 1.125-1.125h2.25c.621 0 1.125.504 1.125 1.125v11.25c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V8.625ZM16.5 4.125c0-.621.504-1.125 1.125-1.125h2.25C20.496 3 21 3.504 21 4.125v15.75c0 .621-.504 1.125-1.125 1.125h-2.25a1.125 1.125 0 0 1-1.125-1.125V4.125Z" />
                        </svg>
                        {{ __('nav.leaderboard') }}
                    </span>
                </x-responsive-nav-link>
            @endauth
        </div>

        <div class="pt-4 pb-1 border-t border-white/5">
            @auth
                <div class="px-4">
                    <div class="flex items-center gap-2">
                        <span class="text-base font-medium text-foreground">{{ Auth::user()->username }}</span>
                        <span class="font-mono text-xs text-muted">lv. {{ Auth::user()->levelData()['level'] }}</span>
                    </div>
                    <div class="text-sm font-medium text-muted">{{ Auth::user()->email }}</div>
                </div>
                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('profile.edit')">{{ __('nav.profile') }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('achievements.index')">{{ __('nav.achievements') }}</x-responsive-nav-link>
                    <span class="flex items-center justify-between gap-2 border-l-4 border-transparent py-2 ps-3 pe-4 text-base font-medium text-muted/50 cursor-not-allowed" title="{{ __('common.coming_soon') }}">
                        {{ __('nav.user_stats') }}
                        <span class="text-[0.6rem] font-mono uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">{{ __('common.soon') }}</span>
                    </span>
                    <x-responsive-nav-link :href="route('friends.index')">{{ __('nav.friends') }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('settings')">{{ __('nav.settings') }}</x-responsive-nav-link>
                    <button type="button"
                        x-on:click="$dispatch('open-modal', 'confirm-sign-out'); open = false"
                        class="group flex w-full items-center gap-2 border-l-4 border-transparent py-2 ps-3 pe-4 text-start text-base font-medium text-muted transition duration-150 ease-in-out hover:border-white/20 hover:bg-surface hover:text-foreground focus:border-white/20 focus:bg-surface focus:text-foreground focus:outline-none">
                        <svg class="h-4 w-4 text-muted/70 transition group-hover:text-danger" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M15.75 9V5.25A2.25 2.25 0 0013.5 3h-6A2.25 2.25 0 005.25 5.25v13.5A2.25 2.25 0 007.5 21h6a2.25 2.25 0 002.25-2.25V15m3 0l3-3m0 0l-3-3m3 3H9" />
                        </svg>
                        <span>{{ __('nav.logout') }}</span>
                    </button>
                </div>
            @else
                <div class="px-4">
                    <div class="text-base font-medium text-foreground">{{ __('common.guest') }}</div>
                </div>
                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('login')">{{ __('nav.login') }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('register')">{{ __('nav.register') }}</x-responsive-nav-link>
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
