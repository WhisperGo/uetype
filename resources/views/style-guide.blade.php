<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>UeType - Design System</title>
    <link rel="icon" type="image/png" href="{{ asset('images/favicon/favicon-uetype.png') }}">

    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=JetBrains+Mono:ital,wght@0,100..800;1,100..800&family=Pixelify+Sans:wght@400..700&family=Press+Start+2P&display=swap" rel="stylesheet">

    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body class="font-mono bg-background text-foreground antialiased">

@php
    $palettes = [
        'primary'   => ['#E8EAF4','#C7CDE5','#9BA5D1','#6C7BBB','#4054A6','#162E93','#13277D','#102168','#0D1A54','#0A1542'],
        'secondary' => ['#F9F5F0','#F1E8DB','#E6D6BE','#DBC3A0','#D0B083','#C69F68','#A88758','#8D714A','#715B3B','#59482F'],
        'accent'    => ['#E8E9EA','#C8C9CB','#9C9FA3','#6E7278','#42474F','#191F28','#151A22','#12161C','#0E1217','#0B0E12'],
        'tertiary'  => ['#F8E8EA','#EEC8CC','#E09DA4','#D26F7A','#C54452','#B81B2C','#9C1725','#83131F','#690F19','#530C14'],
    ];
    $semantics = [
        ['background','Latar halaman'],
        ['surface','Kartu / panel'],
        ['elevated','Panel terangkat / border aktif'],
        ['foreground','Teks utama'],
        ['muted','Teks sekunder'],
        ['border','Garis / pemisah'],
        ['brand','Biru logo / maskot'],
        ['gold','Highlight / koin / aksen hangat'],
        ['danger','Error / missed / loss'],
    ];
    $typeScale = [
        ['h1','text-h1'], ['h2','text-h2'], ['h3','text-h3'], ['h4','text-h4'],
        ['h5','text-h5'], ['h6','text-h6'], ['body','text-body'], ['small','text-small'], ['x-small','text-x-small'],
    ];
@endphp

<div class="max-w-6xl mx-auto px-6 py-16 space-y-20">

    <header class="space-y-3">
        <h1 class="font-display text-h3 text-gold leading-tight">UeType</h1>
        <p class="text-muted text-body">Design System — Foundation tokens, tipografi, dan komponen dasar.</p>
        <p class="text-muted text-small">Acuan tunggal untuk tim. Komponen baru memakai token <span class="text-foreground">semantic</span>, bukan nilai hex mentah.</p>
    </header>

    {{-- Warna primitive --}}
    <section class="space-y-10">
        <h2 class="font-pixel text-h5 text-foreground">Palet Primitive</h2>

        @foreach ($palettes as $name => $shades)
            <div class="space-y-3">
                <h3 class="text-small uppercase tracking-[0.2em] text-muted">{{ $name }}</h3>
                <div class="grid grid-cols-5 sm:grid-cols-10 gap-2">
                    @foreach ($shades as $i => $hex)
                        <div class="space-y-1.5">
                            <div class="h-16 rounded-lg border border-border/40" style="background-color: {{ $hex }}"></div>
                            <div class="text-x-small text-muted leading-tight">
                                <div class="text-foreground">{{ $name }}-{{ $i + 1 }}</div>
                                <div>{{ strtoupper($hex) }}</div>
                            </div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>

    {{-- Warna semantic --}}
    <section class="space-y-6">
        <h2 class="font-pixel text-h5 text-foreground">Token Semantic</h2>
        <p class="text-muted text-small">Yang dipakai komponen. Menunjuk ke primitive lewat CSS variable — ganti tema = ganti :root.</p>
        <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
            @foreach ($semantics as [$name, $desc])
                <div class="rounded-xl border border-border/40 overflow-hidden bg-surface">
                    <div class="h-20" style="background-color: rgb(var(--color-{{ $name }}))"></div>
                    <div class="p-3 space-y-0.5">
                        <div class="text-small text-foreground">{{ $name }}</div>
                        <div class="text-x-small text-muted">{{ $desc }}</div>
                    </div>
                </div>
            @endforeach
        </div>
    </section>

    {{-- Tipografi --}}
    <section class="space-y-8">
        <h2 class="font-pixel text-h5 text-foreground">Tipografi</h2>

        @foreach (['JetBrains Mono (utama)' => 'font-mono', 'Pixelify Sans (piksel lembut)' => 'font-pixel', 'Press Start 2P (display, hemat)' => 'font-display'] as $label => $font)
            <div class="space-y-3 rounded-xl border border-border/40 bg-surface p-6">
                <h3 class="text-small uppercase tracking-[0.2em] text-muted">{{ $label }}</h3>
                <div class="space-y-2">
                    @foreach ($typeScale as [$name, $cls])
                        <div class="flex items-baseline gap-4">
                            <span class="w-16 shrink-0 text-x-small text-muted">{{ $name }}</span>
                            <span class="{{ $font }} {{ $cls }} text-foreground truncate">UeType</span>
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach
    </section>

    {{-- Komponen dasar --}}
    <section class="space-y-6">
        <h2 class="font-pixel text-h5 text-foreground">Komponen Dasar</h2>

        <div class="rounded-xl border border-border/40 bg-surface p-6 space-y-8">
            {{-- Buttons --}}
            <div class="space-y-3">
                <h3 class="text-small uppercase tracking-[0.2em] text-muted">Buttons</h3>
                <div class="flex flex-wrap items-center gap-3">
                    <button class="px-4 py-2 rounded-lg text-small bg-brand text-foreground hover:bg-primary-5 transition-colors">Primary</button>
                    <button class="px-4 py-2 rounded-lg text-small bg-gold text-primary-10 hover:bg-secondary-5 transition-colors">Gold</button>
                    <button class="px-4 py-2 rounded-lg text-small bg-danger text-foreground hover:bg-tertiary-7 transition-colors">Danger</button>
                    <button class="px-4 py-2 rounded-lg text-small border border-border text-foreground hover:bg-elevated transition-colors">Outline</button>
                    <button class="px-4 py-2 rounded-lg text-small text-muted hover:text-foreground transition-colors">Ghost</button>
                </div>
            </div>

            {{-- Badges --}}
            <div class="space-y-3">
                <h3 class="text-small uppercase tracking-[0.2em] text-muted">Badges</h3>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="px-2 py-0.5 rounded text-x-small bg-brand/20 text-primary-2">brand</span>
                    <span class="px-2 py-0.5 rounded text-x-small bg-gold/20 text-gold">gold</span>
                    <span class="px-2 py-0.5 rounded text-x-small bg-danger/20 text-tertiary-3">danger</span>
                    <span class="px-2 py-0.5 rounded text-x-small bg-elevated text-muted">soon</span>
                </div>
            </div>

            {{-- Input --}}
            <div class="space-y-3">
                <h3 class="text-small uppercase tracking-[0.2em] text-muted">Input</h3>
                <input type="text" placeholder="Ketik sesuatu…"
                    class="w-full sm:w-80 px-3 py-2 rounded-lg text-small bg-background border border-border text-foreground placeholder:text-muted focus:outline-none focus:border-brand">
            </div>

            {{-- Stat card --}}
            <div class="space-y-3">
                <h3 class="text-small uppercase tracking-[0.2em] text-muted">Stat Card</h3>
                <div class="grid grid-cols-3 gap-3 max-w-md">
                    @foreach (['wpm' => '112', 'acc' => '97%', 'time' => '30s'] as $k => $v)
                        <div class="rounded-xl bg-elevated/40 border border-border/40 px-4 py-3">
                            <div class="text-x-small uppercase tracking-[0.2em] text-muted">{{ $k }}</div>
                            <div class="text-h5 text-gold tabular-nums">{{ $v }}</div>
                        </div>
                    @endforeach
                </div>
            </div>
        </div>
    </section>

    <footer class="pt-8 border-t border-border/40 text-x-small text-muted">
        UeType Design System · tema default "moonlight".
    </footer>
</div>

</body>
</html>
