{{--
    Ini view Livewire component (app/Livewire/About.php), BUKAN dipanggil
    lewat @extends atau <x-layouts.app>. Livewire yang otomatis membungkus
    isi file ini ke dalam layouts/app.blade.php dan mengisi {{ $slot }} di
    sana, karena component-nya pakai atribut #[Layout('layouts.app')].
    Makanya di sini TIDAK ada <html>, <x-layouts.app>, atau @extends —
    langsung konten saja.
--}}
<div class="text-muted font-mono">
    <div class="max-w-5xl mx-auto px-4 sm:px-6 lg:px-8 pt-10 pb-16">

        {{-- Header --}}
        <div class="mb-10">
            <h1 class="text-fluid-title font-mono font-bold text-foreground mb-2">{{ __('about.title') }}</h1>
            <p class="font-mono text-small text-muted">
                {{ __('about.tagline') }}
            </p>
        </div>

        {{-- What is uetype --}}
        <div class="mb-10">
            <h2 class="text-x-small font-mono uppercase tracking-[0.25em] text-muted mb-3">
                {{ __('about.what_is') }}
            </h2>
            <div class="rounded-xl border border-border bg-surface/60 px-5 py-4">
                <p class="font-mono text-small text-muted leading-relaxed">
                    {{ __('about.what_is_body') }}
                </p>
            </div>
        </div>

        {{-- The team: kartu diklik -> overlay profil (satu modal, isinya diisi Alpine
             dari member yang dipilih, bukan 5 modal terpisah di DOM). --}}
        <div class="mb-10" x-data="{ selected: null }">
            <h2 class="text-x-small font-mono uppercase tracking-[0.25em] text-muted mb-3">
                {{ __('about.team') }}
            </h2>
            <div class="grid grid-cols-2 sm:grid-cols-3 lg:grid-cols-5 gap-3">
                @foreach ($team as $member)
                    @php
                        // File foto dicek dari public/ (bukan storage/) supaya tidak perlu
                        // `php artisan storage:link`. Kalau belum ada filenya, otomatis
                        // fallback ke avatar inisial (gradient bulat + huruf depan nama).
                        $photoPath = $member['photo'] ?? null;
                        $hasPhoto = $photoPath && file_exists(public_path($photoPath));
                        $initial = strtoupper(mb_substr($member['name'], 0, 1));

                        $payload = [
                            'name' => $member['name'],
                            'role' => $member['role'],
                            'photo' => $hasPhoto ? asset($photoPath) : null,
                            'initial' => $initial,
                            'bio' => __('about.bio.' . $member['key']),
                        ];
                    @endphp
                    <button type="button"
                        x-on:click="selected = @js($payload); $dispatch('open-modal', 'team-member')"
                        aria-label="{{ __('about.view_profile', ['name' => $member['name']]) }}"
                        class="flex flex-col items-center text-center rounded-xl border border-border bg-surface/60 px-4 py-6 transition-colors duration-150 hover:border-brand/40 focus:outline-none focus-visible:ring-2 focus-visible:ring-brand">
                        @if ($hasPhoto)
                            <img src="{{ asset($photoPath) }}" alt="{{ $member['name'] }}"
                                class="w-12 h-12 rounded-full object-cover border border-brand/40 mb-3"
                                onerror="this.replaceWith(Object.assign(document.createElement('div'), {className: this.className.replace('object-cover', '') + ' bg-gradient-to-br from-brand/50 to-background flex items-center justify-center text-x-small font-bold text-foreground', textContent: '{{ $initial }}'}))">
                        @else
                            <div class="w-12 h-12 rounded-full bg-gradient-to-br from-brand/50 to-background border border-brand/40 mb-3 flex items-center justify-center text-x-small font-bold text-foreground"
                                aria-hidden="true">
                                {{ $initial }}
                            </div>
                        @endif
                        <span class="font-mono text-small text-foreground font-bold">
                            {{ $member['name'] }}
                        </span>
                        <span class="font-mono text-x-small text-muted mt-1">
                            {{ $member['role'] }}
                        </span>
                    </button>
                @endforeach
            </div>

            {{-- Overlay profil: x-modal sudah menangani backdrop, Esc & klik-luar.
                 Tanpa `focusable`: isinya dibungkus <template x-if>, jadi firstFocusable()
                 bisa undefined saat modal baru dibuka. --}}
            <x-modal name="team-member" maxWidth="lg">
                <template x-if="selected">
                    <div class="p-6 sm:p-8">
                        <div class="flex justify-end -mt-2 -mr-2">
                            <button type="button" x-on:click="$dispatch('close-modal', 'team-member')"
                                class="text-muted hover:text-foreground transition-colors p-1"
                                aria-label="{{ __('about.close') }}">
                                <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                                </svg>
                            </button>
                        </div>

                        <div class="flex flex-col items-center text-center">
                            <template x-if="selected.photo">
                                <img :src="selected.photo" :alt="selected.name"
                                    class="w-28 h-28 sm:w-32 sm:h-32 rounded-full object-cover border-2 border-brand/40 mb-5">
                            </template>
                            <template x-if="! selected.photo">
                                <div class="w-28 h-28 sm:w-32 sm:h-32 rounded-full bg-gradient-to-br from-brand/50 to-background border-2 border-brand/40 mb-5 flex items-center justify-center text-h3 font-bold text-foreground"
                                    aria-hidden="true" x-text="selected.initial"></div>
                            </template>

                            <h3 class="font-mono text-h6 text-foreground font-bold" x-text="selected.name"></h3>
                            <p class="font-mono text-x-small uppercase tracking-[0.2em] text-gold mt-1.5"
                                x-text="selected.role"></p>

                            <p class="font-mono text-small text-muted leading-relaxed mt-5 max-w-sm"
                                x-text="selected.bio"></p>
                        </div>
                    </div>
                </template>
            </x-modal>
        </div>

        {{-- Built with --}}
        <div class="mb-10">
            <h2 class="text-x-small font-mono uppercase tracking-[0.25em] text-muted mb-3">
                {{ __('about.built_with') }}
            </h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($stack as $tech)
                    <span
                        class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full border border-border bg-surface text-x-small font-mono text-muted">
                        <x-dynamic-component :component="$tech['icon']" class="w-4 h-4 shrink-0"
                            style="color: {{ $tech['color'] }}" />
                        {{ $tech['name'] }}
                    </span>
                @endforeach
            </div>
        </div>

        <p class="font-mono text-x-small text-muted/70">
            {{ __('about.disclaimer') }}
        </p>
    </div>
</div>
