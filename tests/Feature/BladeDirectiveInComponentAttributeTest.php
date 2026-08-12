<?php

/**
 * Directive Blade di dalam atribut sebuah component tag tidak pernah dikompilasi.
 *
 * `ComponentTagCompiler` memungut atribut `<x-...>` menjadi string PHP sebelum tahap kompilasi
 * directive sempat berjalan, jadi `@js(...)` di sana lolos MENTAH ke HTML. Kalau atribut itu
 * sebuah handler Alpine, Alpine mencoba mem-parsing sintaks PHP sebagai JavaScript, gagal, dan
 * MENDIAMKAN seluruh handler. Interpolasi `{{ }}` tidak punya masalah ini -- ia ditangani di
 * tahap yang berbeda dan tetap terkompilasi di dalam component tag.
 *
 * Persis begini tombol "Start Attempt" clan war mati: klaim bisa dibuat, percobaan tidak pernah
 * bisa dimulai, dan tidak satu pun test yang merah karena semua test war masuk lewat query param
 * langsung ke TypingEngine.
 *
 * Kegagalannya sunyi di dua sisi -- Blade tidak mengeluh, dan errornya hanya muncul di console
 * browser -- jadi ia butuh penjaga bergaya grep, bukan test perilaku.
 */
it('never leaves a Blade directive inside a component tag attribute', function () {
    $offenders = [];

    foreach (bladeViews() as $path) {
        $markup = tanpaKomentarBlade(file_get_contents($path));

        // Setiap tag komponen pembuka, termasuk yang atributnya membentang banyak baris.
        preg_match_all('/<x-[\w.:-]+(\s[^>]*?)\/?>/s', $markup, $tags, PREG_SET_ORDER);

        foreach ($tags as $tag) {
            $attributes = $tag[1] ?? '';

            // @js(, @class(, @style(, dsb. `@{{ }}` (escape Alpine) sengaja tidak cocok, karena
            // memang dimaksudkan untuk sampai mentah ke browser.
            if (preg_match('/@[a-z]+\s*\(/i', $attributes, $hit)) {
                $line = substr_count(substr($markup, 0, strpos($markup, $tag[0])), "\n") + 1;
                $offenders[] = str_replace(base_path().'/', '', $path).':'.$line.' -> '.$hit[0];
            }
        }
    }

    expect($offenders)->toBe([], 'Directive Blade di dalam atribut component tag tidak dikompilasi; '
        ."pakai {{ }} sebagai gantinya. Ditemukan di:\n".implode("\n", $offenders));
});
