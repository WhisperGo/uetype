@props([
    'title' => null,
    'body' => null,
    'spacing' => '24',
    'card' => false,
])

{{-- Keadaan kosong yang seragam: maskot pudar + judul + keterangan + CTA opsional.

     Sebelumnya blok ini disalin di 8 tempat dan SUDAH menyimpang jadi empat
     varian (py-16/py-24, w-16/w-16 h-16) -- gejala klasik duplikasi yang mulai
     lepas kendali. Maskot dikunci `w-16 h-16` (bukan `w-16` saja) supaya
     tingginya tetap terpesan dan layout tak bergeser saat gambar lambat dimuat.

     CTA sengaja berupa slot, bukan prop: variannya terlalu beragam -- ada yang
     tanpa tombol, satu link wire:navigate, satu button wire:click, sampai tiga
     tombol campuran. --}}
<div @class([
    'flex flex-col items-center justify-center text-center select-none',
    'py-16' => $spacing === '16',
    'py-24' => $spacing !== '16',
    'border border-white/5 rounded-2xl bg-surface/20' => $card,
])>
    <img src="/icon/uetype_mascot.png" alt="" class="w-16 h-16 opacity-30 mb-4">

    @if ($title)
        <p class="font-mono text-sm font-bold text-foreground">{{ $title }}</p>
    @endif

    @if ($body)
        <p @class(['font-mono text-xs text-muted', 'mt-1' => $title])>{{ $body }}</p>
    @endif

    {{-- Slot default dipakai kalau keterangannya bukan string sederhana
         (mis. ekspresi ternary di hasil pencarian clan). --}}
    @if (trim($slot) !== '')
        <div @class(['font-mono text-xs text-muted', 'mt-1' => $title])>{{ $slot }}</div>
    @endif

    @isset($cta)
        <div class="flex flex-wrap items-center justify-center gap-3 mt-5">{{ $cta }}</div>
    @endisset
</div>
