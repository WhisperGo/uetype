<?php

namespace App\Services;

/**
 * Turns the client's raw error stream into the result page's error-marker view model.
 * Pure functions, no state/DB.
 *
 * Two jobs, one wire format:
 * - sanitize(): trust boundary for the client payload (runs in TypingEngine::saveResult).
 * - inspect():  maps each error onto a chart second and reconstructs the word it happened
 *               in, from textToType (runs at TypingResult render).
 */
class TypingErrorInspector
{
    /**
     * Batas event yang diterima. 500 error dalam satu tes ≈ akurasi di bawah 50% pada
     * mode time 120 -- itu mashing, bukan latihan. Di atas cap, titik grafik & panel
     * ikut terpotong sementara heatmap tidak (lihat docs: divergensi tiga-angka).
     */
    public const MAX_EVENTS = 500;

    /**
     * Validasi bentuk + cast payload klien. Tier presentasi (session-only, tak pernah
     * menyentuh skor/XP/PB/leaderboard), jadi tak ada urusan dengan AntiCheatService --
     * tapi tetap disanitasi seperti preseden ghost.
     *
     * Beda dari missedChars yang mentah tapi aman karena cuma dibaca lewat lookup kunci
     * yang sudah diketahui: di sini `actual` benar-benar DIRENDER, jadi tak boleh ada
     * string sembarang dari klien yang lolos.
     *
     * @return list<array{second:int,index:int,actual:?string}>
     */
    public static function sanitize(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }

        $clean = [];

        foreach ($raw as $event) {
            if (count($clean) >= self::MAX_EVENTS) {
                break;
            }

            if (! is_array($event)
                || ! isset($event['second'], $event['index'])
                || ! is_numeric($event['second'])
                || ! is_numeric($event['index'])) {
                continue;
            }

            $second = (int) $event['second'];
            $index = (int) $event['index'];

            // Koordinat negatif mustahil dari jalur normal.
            if ($second < 0 || $index < 0) {
                continue;
            }

            // Dipotong ke 1 karakter: itu memang semua yang bisa dihasilkan handleInput
            // (e.key.length dijaga === 1 di sana). null = karakter DILEWATI, state sah.
            $actual = $event['actual'] ?? null;
            $actual = is_string($actual) && $actual !== '' ? mb_substr($actual, 0, 1) : null;

            $clean[] = ['second' => $second, 'index' => $index, 'actual' => $actual];
        }

        return $clean;
    }

    /**
     * Build the chart/panel view model: per-second error counts plus, for each error,
     * the word it landed in and what was typed instead.
     *
     * @param  list<array{second:int,index:int,actual:?string}>  $events  sudah lewat sanitize()
     * @param  string|null  $textToType  null -> degradasi anggun (word/expected jadi null)
     * @param  int  $sampleCount  count($wpmHistory) -- panjang sumbu x grafik
     * @return array{counts: list<int>, events: list<array{second:int,label:int,word:?string,offset:?int,expected:?string,actual:?string}>}
     */
    public static function inspect(array $events, ?string $textToType, int $sampleCount): array
    {
        $sampleCount = max(0, $sampleCount);
        $counts = array_fill(0, $sampleCount, 0);

        // Tanpa sampel wpmHistory tak ada sumbu x untuk ditempeli -- grafiknya memang
        // kosong. Heatmap tetap utuh (sumbernya missedChars, jalur terpisah).
        if ($sampleCount === 0 || $events === []) {
            return ['counts' => $counts, 'events' => []];
        }

        // mb_str_split: server mengindeks per CODEPOINT, klien (targetArray = split(''))
        // per UTF-16 code unit. Identik sampai U+FFFF; wordlist en/id murni a-z, jadi
        // index selalu cocok. Karakter astral (emoji) di teks akan membuatnya meleset.
        $chars = ($textToType !== null && $textToType !== '') ? mb_str_split($textToType) : [];
        $bounds = self::wordBounds($chars);

        $enriched = [];

        foreach ($events as $event) {
            // Detik terakhir tes `time` tak pernah masuk wpmHistory: tick yang memicu
            // finish() men-set isFinished sebelum baris push-nya jalan. Error di detik
            // itu di-CLAMP ke sampel terakhir, BUKAN dibuang -- membuangnya merusak
            // invarian "jumlah titik === jumlah missedChars" yang dipakai halaman ini
            // untuk menyamakan grafik dengan heatmap tepat di bawahnya.
            $second = min($event['second'], $sampleCount - 1);
            $counts[$second]++;

            $word = self::wordAt($bounds, $event['index']);

            $enriched[] = [
                'second' => $second,
                // label = yang tertulis di sumbu x. Di-emit eksplisit supaya tak ada
                // template yang berhitung sendiri -- off-by-one adalah risiko utama fitur ini.
                'label' => $second + 1,
                'word' => $word['text'] ?? null,
                'offset' => $word !== null ? $event['index'] - $word['start'] : null,
                'expected' => $chars[$event['index']] ?? null,
                'actual' => $event['actual'],
            ];
        }

        return ['counts' => $counts, 'events' => $enriched];
    }

    /**
     * Batas kata (index absolut) dari teks target. Cerminan perakitan wordBounds di
     * typingGame(): kata = deretan karakter antar spasi. Satu beda yang disengaja --
     * kata kosong (spasi ganda) dilewati, supaya teks cacat tak melahirkan kata
     * dengan end < start.
     *
     * @param  list<string>  $chars
     * @return list<array{start:int,end:int,text:string}>
     */
    private static function wordBounds(array $chars): array
    {
        $bounds = [];
        $start = 0;
        $len = count($chars);

        for ($i = 0; $i < $len; $i++) {
            if ($chars[$i] !== ' ') {
                continue;
            }

            if ($i > $start) {
                $bounds[] = [
                    'start' => $start,
                    'end' => $i - 1,
                    'text' => implode('', array_slice($chars, $start, $i - $start)),
                ];
            }

            $start = $i + 1;
        }

        // Kata terakhir tak diakhiri spasi -- cabang inilah yang menangani "error di
        // kata terakhir", yang di klien tak pernah lewat completeWord().
        if ($len > $start) {
            $bounds[] = [
                'start' => $start,
                'end' => $len - 1,
                'text' => implode('', array_slice($chars, $start)),
            ];
        }

        return $bounds;
    }

    /**
     * @param  list<array{start:int,end:int,text:string}>  $bounds
     * @return array{start:int,end:int,text:string}|null
     */
    private static function wordAt(array $bounds, int $index): ?array
    {
        foreach ($bounds as $word) {
            if ($index < $word['start']) {
                return null;    // jatuh di celah spasi
            }

            if ($index <= $word['end']) {
                return $word;
            }
        }

        return null;            // di luar teks
    }
}
