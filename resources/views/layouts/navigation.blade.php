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
                        {{ __('Solo') }}
                    </x-nav-link>
                    <x-nav-link href="{{ url('/multiplayer') }}" :active="request()->is('multiplayer')">
                        {{ __('Multiplayer') }}
                    </x-nav-link>
                    <span
                        class="inline-flex items-center gap-1.5 px-3 pt-1 text-sm font-medium leading-5 text-muted/50 cursor-not-allowed"
                        title="Segera hadir">
                        {{ __('Klan') }}
                        <span
                            class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">soon</span>
                    </span>
                </div>
            </div>

            <!-- Right side -->
            <div class="hidden sm:flex sm:items-center sm:gap-4 sm:ms-6">
                @auth
                    <!-- Quick stats pill -->
                    <div
                        class="flex items-center gap-3 px-3 py-1.5 rounded-full bg-surface border border-white/5 text-xs font-mono">
                        <span class="flex items-center gap-1 text-foreground" title="Highest WPM">
                            <svg class="w-3.5 h-3.5 text-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            {{ rtrim(rtrim(number_format(Auth::user()->highest_wpm, 1), '0'), '.') }}
                        </span>
                        <span class="flex items-center gap-1 text-gold" title="Koin">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"
                                    fill="none" />
                                <circle cx="10" cy="10" r="3.5" />
                            </svg>
                            {{ Auth::user()->coins }}
                        </span>
                    </div>

                    <!-- Trophy shortcut -> Leaderboard -->
                    <a href="{{ route('leaderboard') }}" title="{{ __('Leaderboard') }}"
                        class="p-2 rounded-lg border {{ request()->is('leaderboard') ? 'text-gold border-gold/40 bg-gold/10' : 'text-muted border-transparent hover:text-gold hover:border-gold/30 hover:bg-surface' }} focus:outline-none focus-visible:ring-1 focus-visible:ring-gold/40 transition">
                        <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                            <path stroke-linecap="round" stroke-linejoin="round"
                                d="M8 21h8m-4-4v4m6.5-17H21v2a4 4 0 01-4 4m-11-6H3v2a4 4 0 004 4m1-9h8v5a5 5 0 01-10 0V3z" />
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
                                <span class="font-medium text-sm text-foreground px-1">Tamu</span>
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
                            <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>

                            <x-dropdown-link :href="route('achievements.index')">{{ __('Achievements') }}</x-dropdown-link>

                            <span class="flex items-center justify-between w-full px-4 py-2 text-sm text-muted/50 cursor-not-allowed" title="Segera hadir">
                                {{ __('User Stats') }}
                                <span class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">soon</span>
                            </span>
                            <span class="flex items-center justify-between w-full px-4 py-2 text-sm text-muted/50 cursor-not-allowed" title="Segera hadir">
                                {{ __('Friends List') }}
                                <span class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">soon</span>
                            </span>
                            <span class="flex items-center justify-between w-full px-4 py-2 text-sm text-muted/50 cursor-not-allowed" title="Segera hadir">
                                {{ __('Settings') }}
                                <span class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">soon</span>
                            </span>

                            <div class="my-1 border-t border-white/5"></div>

                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                    {{ __('Sign Out') }}
                                </x-dropdown-link>
                            </form>
                        @else
                            <x-dropdown-link :href="route('login')">{{ __('Log In') }}</x-dropdown-link>
                            <x-dropdown-link :href="route('register')">{{ __('Register') }}</x-dropdown-link>
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
                :active="request()->is('typing')">{{ __('Solo') }}</x-responsive-nav-link>
            <x-responsive-nav-link href="{{ url('/multiplayer') }}"
                :active="request()->is('multiplayer')">{{ __('Multiplayer') }}</x-responsive-nav-link>
            {{-- <span
                class="flex items-center w-full gap-2 py-2 text-base font-medium cursor-not-allowed ps-3 pe-4 text-muted/50">
                {{ __('Multiplayer') }}
                <span
                    class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">soon</span>
            </span> --}}
            <span
                class="flex items-center w-full gap-2 py-2 text-base font-medium cursor-not-allowed ps-3 pe-4 text-muted/50">
                {{ __('Klan') }}
                <span
                    class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-muted/60">soon</span>
            </span>
            <x-responsive-nav-link href="{{ url('/leaderboard') }}" :active="request()->is('leaderboard')">
                <span class="flex items-center gap-2">
                    <svg class="w-4 h-4 text-gold" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                        <path stroke-linecap="round" stroke-linejoin="round"
                            d="M8 21h8m-4-4v4m6.5-17H21v2a4 4 0 01-4 4m-11-6H3v2a4 4 0 004 4m1-9h8v5a5 5 0 01-10 0V3z" />
                    </svg>
                    {{ __('Leaderboard') }}
                </span>
            </x-responsive-nav-link>
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
                    <x-responsive-nav-link :href="route('profile.edit')">{{ __('Profile') }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('achievements.index')">{{ __('Achievements') }}</x-responsive-nav-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                            {{ __('Sign Out') }}
                        </x-responsive-nav-link>
                    </form>
                </div>
            @else
                <div class="px-4">
                    <div class="text-base font-medium text-foreground">Tamu</div>
                </div>
                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('login')">{{ __('Log In') }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('register')">{{ __('Register') }}</x-responsive-nav-link>
                </div>
            @endauth
        </div>
    </div>
</nav>
