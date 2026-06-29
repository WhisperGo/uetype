<nav x-data="{ open: false }" class="sticky top-0 z-40 border-b border-white/5 bg-typing-bg/80 backdrop-blur-md">
    <div class="px-4 mx-auto max-w-7xl sm:px-6 lg:px-8">
        <div class="flex justify-between h-16">
            <div class="flex">
                <!-- Logo / Wordmark -->
                <div class="flex items-center shrink-0">
                    <a href="{{ route('home') }}" class="flex items-center gap-2 group">
                        <x-application-logo class="block w-auto h-8 transition-transform group-hover:scale-110" />
                        <span class="font-sans text-xl font-bold tracking-tight text-typing-text">Ue<span
                                class="text-typing-accent">Type</span></span>
                    </a>
                </div>

                <!-- Navigation Links -->
                <div class="hidden sm:-my-px sm:ms-10 sm:flex sm:gap-1">
                    <x-nav-link href="{{ url('/typing') }}" :active="request()->is('typing')">
                        {{ __('Solo') }}
                    </x-nav-link>
                    <span
                        class="inline-flex items-center gap-1.5 px-3 pt-1 text-sm font-medium leading-5 text-typing-muted/50 cursor-not-allowed"
                        title="Segera hadir">
                        {{ __('Multiplayer') }}
                        <span
                            class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-typing-muted/60">soon</span>
                    </span>
                    <span
                        class="inline-flex items-center gap-1.5 px-3 pt-1 text-sm font-medium leading-5 text-typing-muted/50 cursor-not-allowed"
                        title="Segera hadir">
                        {{ __('Klan') }}
                        <span
                            class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-typing-muted/60">soon</span>
                    </span>
                    <x-nav-link href="{{ url('/leaderboard') }}" :active="request()->is('leaderboard')">
                        {{ __('Leaderboard') }}
                    </x-nav-link>
                </div>
            </div>

            <!-- Right side -->
            <div class="hidden sm:flex sm:items-center sm:gap-4 sm:ms-6">
                @auth
                    <!-- Quick stats pill -->
                    <div
                        class="flex items-center gap-3 px-3 py-1.5 rounded-full bg-typing-surface border border-white/5 text-xs font-mono">
                        <span class="flex items-center gap-1 text-typing-accent" title="Highest WPM">
                            <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor"
                                stroke-width="2">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M13 10V3L4 14h7v7l9-11h-7z" />
                            </svg>
                            {{ rtrim(rtrim(number_format(Auth::user()->highest_wpm, 1), '0'), '.') }}
                        </span>
                        <span class="flex items-center gap-1 text-typing-gold" title="Koin">
                            <svg class="w-3.5 h-3.5" fill="currentColor" viewBox="0 0 20 20">
                                <circle cx="10" cy="10" r="8" stroke="currentColor" stroke-width="1.5"
                                    fill="none" />
                                <circle cx="10" cy="10" r="3.5" />
                            </svg>
                            {{ Auth::user()->coins }}
                        </span>
                    </div>
                @endauth

                <!-- Settings Dropdown -->
                <x-dropdown align="right" width="48">
                    <x-slot name="trigger">
                        <button
                            class="inline-flex items-center gap-2 px-3 py-2 text-sm font-medium leading-4 transition rounded-lg text-typing-muted hover:text-typing-text hover:bg-typing-surface focus:outline-none">
                            @auth
                                <!-- PEMERIKSAAN FOTO PROFIL / AVATAR -->
                                @if (Auth::user()->avatar)
                                    <!-- Tampilkan Foto Profil Asli dari Google -->
                                    <img src="{{ Auth::user()->avatar }}" alt="{{ Auth::user()->username }}"
                                        class="object-cover rounded-full w-7 h-7 border border-white/10 shadow-sm transition duration-200"
                                        referrerpolicy="no-referrer">
                                @else
                                    <!-- Cadangan (Fallback) Inisial Huruf jika Daftar Manual -->
                                    <span
                                        class="flex items-center justify-center text-xs font-bold uppercase rounded-full w-7 h-7 bg-gradient-to-br from-typing-accent to-typing-accent2 text-typing-bg">
                                        {{ Str::substr(Auth::user()->username, 0, 1) }}
                                    </span>
                                @endif

                                <span class="font-medium text-typing-text">{{ Auth::user()->username }}</span>
                            @else
                                <span class="font-medium text-typing-text">Tamu</span>
                            @endauth

                            <svg class="w-4 h-4 fill-current" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                <path fill-rule="evenodd"
                                    d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z"
                                    clip-rule="evenodd" />
                            </svg>
                        </button>
                    </x-slot>

                    <x-slot name="content">
                        @auth
                            <x-dropdown-link :href="route('profile.edit')">{{ __('Profile') }}</x-dropdown-link>
                            <form method="POST" action="{{ route('logout') }}">
                                @csrf
                                <x-dropdown-link :href="route('logout')"
                                    onclick="event.preventDefault(); this.closest('form').submit();">
                                    {{ __('Log Out') }}
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
                    class="inline-flex items-center justify-center p-2 transition rounded-md text-typing-muted hover:text-typing-text hover:bg-typing-surface focus:outline-none">
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
            <span
                class="flex items-center w-full gap-2 py-2 text-base font-medium cursor-not-allowed ps-3 pe-4 text-typing-muted/50">
                {{ __('Multiplayer') }}
                <span
                    class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-typing-muted/60">soon</span>
            </span>
            <span
                class="flex items-center w-full gap-2 py-2 text-base font-medium cursor-not-allowed ps-3 pe-4 text-typing-muted/50">
                {{ __('Klan') }}
                <span
                    class="text-[0.6rem] font-sans uppercase tracking-wider px-1 py-0.5 rounded bg-white/5 text-typing-muted/60">soon</span>
            </span>
        </div>

        <div class="pt-4 pb-1 border-t border-white/5">
            @auth
                <div class="px-4">
                    <div class="text-base font-medium text-typing-text">{{ Auth::user()->username }}</div>
                    <div class="text-sm font-medium text-typing-muted">{{ Auth::user()->email }}</div>
                </div>
                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('profile.edit')">{{ __('Profile') }}</x-responsive-nav-link>
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <x-responsive-nav-link :href="route('logout')"
                            onclick="event.preventDefault(); this.closest('form').submit();">
                            {{ __('Log Out') }}
                        </x-responsive-nav-link>
                    </form>
                </div>
            @else
                <div class="px-4">
                    <div class="text-base font-medium text-typing-text">Tamu</div>
                </div>
                <div class="mt-3 space-y-1">
                    <x-responsive-nav-link :href="route('login')">{{ __('Log In') }}</x-responsive-nav-link>
                    <x-responsive-nav-link :href="route('register')">{{ __('Register') }}</x-responsive-nav-link>
                </div>
            @endauth
        </div>
    </div>
</nav>
