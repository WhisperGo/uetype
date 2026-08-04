<?php

use Illuminate\Support\Facades\DB;

/**
 * Indeks `typing_results` harus cocok dengan BENTUK query leaderboard yang sebenarnya.
 *
 * Ini bukan test performa (tak ada yang mengukur waktu di sini) melainkan test terhadap DRIFT:
 * tiga migrasi berturut-turut menambah dimensi ke query yang sama tanpa saling tahu, sampai
 * akhirnya tak ada satu pun indeks yang menutupinya -- dan tak ada yang berubah warna. Yang
 * dikunci di sini adalah URUTAN kolomnya: kolom filter dulu, metrik paling belakang. Menyisipkan
 * dimensi baru (mis. bahasa berikutnya) di tempat yang salah akan langsung merah.
 *
 * MySQL-only, sama seperti proyeknya (lihat catatan di phpunit.xml).
 */

/** @return list<string> nama kolom indeks, urut posisi */
function indexColumns(string $table, string $index): array
{
    return collect(DB::select("SHOW INDEX FROM {$table} WHERE Key_name = ?", [$index]))
        ->sortBy('Seq_in_index')
        ->pluck('Column_name')
        ->values()
        ->all();
}

it('covers the leaderboard wpm query in filter order, metric last', function () {
    expect(indexColumns('typing_results', 'typing_results_leaderboard_wpm_index'))
        ->toBe(['mode', 'mode_config', 'language', 'review_status', 'net_wpm']);
});

it('covers the survival board, which ranks by duration rather than wpm', function () {
    expect(indexColumns('typing_results', 'typing_results_leaderboard_duration_index'))
        ->toBe(['mode', 'mode_config', 'language', 'review_status', 'duration_seconds']);
});

it('covers the eligibility gate, which sums duration per user across every mode', function () {
    expect(indexColumns('typing_results', 'typing_results_eligibility_index'))
        ->toBe(['user_id', 'duration_seconds']);
});

it('keeps no review index, whose every prefix the wpm index already serves', function () {
    // Dipertahankan sebagai test tersendiri: indeks berlebih tak pernah membuat query salah,
    // jadi tak ada test lain yang akan menangkapnya kalau suatu saat dihidupkan lagi -- ia
    // hanya menambah biaya tulis di tabel yang paling sering ditulis.
    expect(indexColumns('typing_results', 'typing_results_review_index'))->toBe([]);
});
