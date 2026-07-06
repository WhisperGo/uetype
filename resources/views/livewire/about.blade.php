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
            <p class="font-sans text-small text-muted">
                {{ __('about.tagline') }}
            </p>
        </div>

        {{-- What is uetype --}}
        <div class="mb-10">
            <h2 class="text-x-small font-sans uppercase tracking-[0.25em] text-muted mb-3">
                {{ __('about.what_is') }}
            </h2>
            <div class="rounded-xl border border-border bg-surface/60 px-5 py-4">
                <p class="font-sans text-small text-muted leading-relaxed">
                    {{ __('about.what_is_body') }}
                </p>
            </div>
        </div>

        {{-- The team --}}
        <div class="mb-10">
            <h2 class="text-x-small font-sans uppercase tracking-[0.25em] text-muted mb-3">
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
                    @endphp
                    <div
                        class="flex flex-col items-center text-center rounded-xl border border-border bg-surface/60 px-4 py-6 transition-colors duration-150 hover:border-brand/40">
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
                        <span class="font-sans text-small text-foreground font-bold">
                            {{ $member['name'] }}
                        </span>
                        <span class="font-sans text-x-small text-muted mt-1">
                            {{ $member['role'] }}
                        </span>
                    </div>
                @endforeach
            </div>
        </div>

        {{-- Built with --}}
        <div class="mb-10">
            <h2 class="text-x-small font-sans uppercase tracking-[0.25em] text-muted mb-3">
                {{ __('about.built_with') }}
            </h2>
            <div class="flex flex-wrap gap-2">
                @foreach ($stack as $tech)
                    <span
                        class="px-3 py-1.5 rounded-full border border-border bg-surface text-x-small font-sans text-muted">
                        {{ $tech }}
                    </span>
                @endforeach
            </div>
        </div>

        <p class="font-sans text-x-small text-muted/70">
            {{ __('about.disclaimer') }}
        </p>
    </div>
</div>
