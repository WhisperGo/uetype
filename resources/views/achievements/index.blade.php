<x-app-layout>
    <x-slot name="header">
        <div class="max-w-5xl px-4 mx-auto sm:px-6 lg:px-8">
            <h2 class="font-display text-fluid-title tracking-wide text-foreground">
                {{ __('achievements.header') }}
            </h2>
        </div>
    </x-slot>

    @php
        $progressPercent = $total > 0 ? ($earnedCount / $total) * 100 : 0;
    @endphp

    <div class="py-10"
        x-data="{
            filter: 'all',
            visible(cat) { return this.filter === 'all' || this.filter === cat; }
        }">
        <div class="max-w-5xl px-4 mx-auto space-y-6 sm:px-6 lg:px-8">

            <!-- Back to Profile -->
            <a href="{{ route('profile.me') }}"
                class="inline-flex items-center gap-1.5 font-mono text-sm text-muted hover:text-foreground transition-colors">
                <svg class="w-3.5 h-3.5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7" />
                </svg>
                {{ __('achievements.back_to_profile') }}
            </a>

            <!-- Heading + progress -->
            <div class="space-y-3">
                <div>
                    <h1 class="font-display text-fluid-title tracking-wide text-foreground">{{ __('achievements.header') }}</h1>
                    <p class="mt-1 font-mono text-sm text-muted">
                        {{ __('achievements.unlocked_count', ['count' => $earnedCount, 'total' => $total]) }}
                    </p>
                </div>
                <div class="h-2 overflow-hidden rounded-full bg-white/5">
                    <div class="h-full rounded-full bg-gradient-to-r from-brand to-gold transition-all duration-500"
                        style="width: {{ $progressPercent }}%"></div>
                </div>
            </div>

            <!-- Category filters -->
            <div class="flex flex-wrap gap-2">
                @foreach ($categories as $cat)
                    <button type="button"
                        @click="filter = '{{ $cat['key'] }}'"
                        :class="filter === '{{ $cat['key'] }}'
                            ? 'bg-brand-bright text-background border-brand-bright'
                            : 'bg-surface/60 text-muted border-white/10 hover:text-foreground hover:border-white/20'"
                        class="px-4 py-1.5 rounded-lg border font-mono text-xs font-semibold transition-colors">
                        {{ __('achievements.categories.'.$cat['key']) }}
                    </button>
                @endforeach
            </div>

            <!-- Achievement grid -->
            <div class="grid grid-cols-1 gap-3 md:grid-cols-2 lg:grid-cols-3 2xl:grid-cols-4">
                @foreach ($achievements as $a)
                    <div x-show="visible('{{ $a['category'] }}')" x-transition.opacity
                        class="flex items-center gap-4 p-4 border rounded-2xl transition
                            {{ $a['earned']
                                ? 'bg-surface/60 border-white/10'
                                : 'bg-surface/20 border-white/5 opacity-50' }}">

                        <!-- Icon badge -->
                        <div class="flex flex-col items-center justify-center w-14 h-14 rounded-xl shrink-0 border
                            {{ $a['earned']
                                ? 'bg-brand/15 border-brand/40 text-brand-bright'
                                : 'bg-white/5 border-white/10 text-muted' }}">
                            <span class="font-mono text-sm font-bold leading-none">{{ $a['icon_value'] }}</span>
                            <span class="mt-0.5 text-[0.55rem] font-mono uppercase tracking-wider {{ $a['earned'] ? 'text-brand-bright/70' : 'text-muted/60' }}">
                                {{ $a['icon_unit'] }}
                            </span>
                        </div>

                        {{-- Text.

                             Tinggi DIPESAN, bukan lebar diperlebar. Pada grid 4 kolom ruang teks
                             hanya 127px, sementara deskripsi terpanjang butuh 158px (Indonesia:
                             173px). `truncate` dulu memotongnya permanen -- dan halaman ini tak
                             punya tooltip, jadi teksnya benar-benar tak bisa dibaca. Sekarang
                             teks boleh memakai dua baris, dan KEDUA blok memesan tinggi dua
                             baris supaya semua kartu tetap setinggi sama apa pun isinya:
                             sebelumnya kartu "Earned" satu baris lebih tinggi, dan CSS grid
                             meregangkan seluruh sel sebaris ikut naik. `leading-4` dipasang
                             eksplisit agar dua baris jatuh tepat di 2rem. --}}
                        <div class="min-w-0 flex-1">
                            <h3 class="font-mono text-sm font-bold leading-5 text-foreground truncate"
                                title="{{ __('achievements.defs.'.$a['key'].'.title') }}">{{ __('achievements.defs.'.$a['key'].'.title') }}</h3>

                            <p class="mt-0.5 min-h-[2rem] font-mono text-xs leading-4 text-muted">{{ __('achievements.defs.'.$a['key'].'.description') }}</p>

                            <div class="mt-1 min-h-[2rem] font-mono text-[0.7rem] leading-4">
                                @if ($a['earned'])
                                    <p class="flex items-center gap-1 text-gold">
                                        <svg class="w-3 h-3 shrink-0" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="3">
                                            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
                                        </svg>
                                        {{ __('achievements.earned') }}
                                    </p>
                                    {{-- Tanggal berdiri di barisnya sendiri DENGAN SENGAJA: digabung
                                         dengan "Earned" ia butuh 147px di ruang 127px, dan patahannya
                                         jatuh di tengah tanggal ("· 25 Jul" / "2026"). --}}
                                    @if ($a['unlocked_at'])
                                        <p class="text-muted/70">@localtime($a['unlocked_at'], 'd M Y')</p>
                                    @endif
                                @else
                                    <p class="text-muted/60">{{ __('achievements.locked') }}</p>
                                @endif
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>

        </div>
    </div>
</x-app-layout>
