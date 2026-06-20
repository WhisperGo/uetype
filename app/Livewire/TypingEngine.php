<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Text;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\TypingResult;
use App\Services\AntiCheatService;

class TypingEngine extends Component
{
    // Mode Utama: 'time', 'words', 'quote'
    public $mainMode = 'time';

    // Sub Mode (Pilihan angka/panjang)
    public $subMode = '30';

    public $textToType;

    // ID baris `texts` yang sedang diketik. Terisi untuk mode quote (teks dari DB),
    // null untuk time/words (teks dirakit acak dari wordlist JSON, bukan dari satu baris texts).
    public $textId = null;

    public function mount()
    {
        // Pulihkan preferensi sebelumnya jika ada
        if (session()->has('typing_preferences')) {
            $prefs = session('typing_preferences');
            $this->mainMode = $prefs['mode'] ?? 'time';
            $this->subMode = $prefs['subMode'] ?? '30';
        }

        $this->generateText();
    }

    // Fungsi untuk mengganti mode dan ambil teks baru
    public function setMode($main, $sub)
    {
        $this->mainMode = $main;
        $this->subMode = $sub;

        // Simpan preferensi pengguna ke session agar tidak reset
        session()->put('typing_preferences', [
            'mode' => $main,
            'subMode' => $sub
        ]);
        session()->save();

        $this->generateText();

        // Kirim event dengan detail teks baru, mode, dan sub-mode
        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    public function restart()
    {
        $this->generateText(); // Ambil teks baru berdasarkan mainMode & subMode yang ada

        $this->dispatch(
            'mode-changed',
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    public function generateText()
    {
        if ($this->mainMode === 'quote') {
            $text = Text::where('mode', 'quote')->inRandomOrder()->first();
            $this->textToType = $text ? $text->content : "Kutipan belum tersedia di database.";
            // Simpan ID quote agar hasil sesi bisa mereferensikan teks yang diketik.
            $this->textId = $text?->id;
        } else {
            // Teks time/words dirakit acak dari JSON, tidak terikat ke satu baris texts.
            $this->textId = null;

            // Mode time atau words menggunakan file JSON
            // Secara default menggunakan english.json (bisa disesuaikan nanti dengan state bahasa)
            $path = base_path('database/data/indonesian.json');

            if (File::exists($path)) {
                $jsonString = File::get($path);
                $data = json_decode($jsonString, true);

                // Pastikan data['words'] ada dan berupa array
                if (is_array($data) && isset($data['words']) && is_array($data['words'])) {
                    $wordsArray = $data['words'];
                    shuffle($wordsArray);

                    // Jika mode words, kita berikan sesuai jumlah yang dipilih.
                    // Survival tidak punya batas waktu/kata, jadi butuh stok kata panjang
                    // (kalau benar-benar habis, finish() tetap terpanggil saat teks selesai).
                    // Selain itu (time) cukup 350 kata.
                    $limit = match ($this->mainMode) {
                        'words' => (int) $this->subMode,
                        'survival' => 500,
                        default => 350,
                    };

                    $selectedWords = [];
                    while (count($selectedWords) < $limit) {
                        shuffle($wordsArray);
                        $needed = $limit - count($selectedWords);
                        $selectedWords = array_merge($selectedWords, array_slice($wordsArray, 0, $needed));
                    }

                    $this->textToType = implode(' ', $selectedWords);
                } else {
                    $this->textToType = "error: struktur file json tidak valid";
                }
            } else {
                $this->textToType = "error: file wordlist tidak ditemukan";
            }
        }
    }

    public function saveResult($durationMs, $totalKeystrokes, $correctKeystrokes, $wpmHistory = [], $rawHistory = [], $missedChars = [], $wordsCompleted = 0)
    {
        // Catatan: WPM/akurasi dari client TIDAK diterima sebagai parameter — server
        // selalu menghitung ulang sendiri dari jumlah karakter & durasi (anti-cheat).
        $totalKeystrokes = (int) $totalKeystrokes;
        $correctKeystrokes = (int) $correctKeystrokes;
        $incorrectKeystrokes = max(0, $totalKeystrokes - $correctKeystrokes);

        // Skor survival = jumlah kata bersih yang berhasil diketik sebelum nyawa habis
        // (inilah angka yang masuk leaderboard survival — requirement Survival/Scoring).
        // Mode lain tidak memakai kolom score.
        $score = $this->mainMode === 'survival' ? max(0, (int) $wordsCompleted) : null;

        // Durasi dikirim client dalam MILIDETIK (presisi penuh, sama dengan perhitungan live).
        // Simpan dalam detik (boleh pecahan) agar WPM server == WPM yang dilihat user saat mengetik.
        $duration = max(0.0, (float) $durationMs / 1000);

        // Validasi kewajaran server-side (requirement Scoring & Stats bag. 5):
        // server HITUNG ULANG WPM/akurasi dari karakter & durasi (bukan percaya angka client),
        // lalu menjadi gerbang — sesi yang tidak masuk akal DITOLAK, bukan disimpan.
        $check = app(AntiCheatService::class)->check(
            $correctKeystrokes,
            $totalKeystrokes,
            $duration,
        );

        // Angka final selalu pakai hasil hitung ulang server (sumber kebenaran).
        $finalNetWpm = $check['net_wpm'];
        $finalRawWpm = $check['raw_wpm'];
        $finalAccuracy = $check['accuracy'];

        // Sesi tidak valid: tolak. Jangan simpan, jangan beri EXP, jangan naikkan rekor.
        if (! $check['valid']) {
            session()->flash('result_rejected', 'Hasil sesi ini ditolak oleh validasi server (tidak masuk akal) dan tidak disimpan.');

            return $this->redirect(route('typing'), navigate: true);
        }

        // EXP diperoleh dari hasil tervalidasi server (skala dengan akurasi).
        $xpEarned = (int) round($finalNetWpm * ($finalAccuracy / 100));

        if (Auth::check()) {
            $user = Auth::user();

            DB::transaction(function () use (
                $user, $duration, $finalNetWpm, $finalRawWpm, $finalAccuracy,
                $correctKeystrokes, $incorrectKeystrokes, $xpEarned, $score,
                $wpmHistory, $rawHistory, $missedChars
            ) {
                // Satu sesi solo valid = satu baris di typing_results (requirement Database).
                TypingResult::create([
                    'user_id' => $user->id,
                    'text_id' => $this->textId, // terisi untuk quote, null untuk time/words
                    'mode' => $this->mainMode, // 'time' | 'words' | 'quote'
                    'mode_config' => $this->mainMode === 'quote' ? null : (string) $this->subMode,
                    'net_wpm' => $finalNetWpm,
                    'raw_wpm' => $finalRawWpm,
                    'accuracy' => $finalAccuracy,
                    'correct_chars' => $correctKeystrokes,
                    'incorrect_chars' => $incorrectKeystrokes,
                    'duration_seconds' => $duration,
                    'score' => $score, // jumlah kata bersih (survival), null untuk mode lain
                    'xp_earned' => $xpEarned,
                    'ghost_data' => null, // diisi selektif oleh ghost mode nanti
                ]);

                // Akumulasi EXP ke total user & perbarui rekor WPM (hanya sesi valid sampai sini).
                $user->total_xp += $xpEarned;

                if ($finalNetWpm > (float) $user->highest_wpm) {
                    $user->highest_wpm = $finalNetWpm;
                }

                $user->save();
            });
        }

        session()->put('typing_result', [
            'wpm' => $finalNetWpm,
            'rawWpm' => $finalRawWpm,
            'accuracy' => $finalAccuracy,
            'time' => $duration,
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
            'score' => $score, // kata bersih survival (null untuk mode lain)
            'totalKeystrokes' => $totalKeystrokes,
            'correctKeystrokes' => $correctKeystrokes,
            'incorrectKeystrokes' => $incorrectKeystrokes,
            'wpmHistory' => $wpmHistory,
            'rawHistory' => $rawHistory,
            'missedChars' => $missedChars,
        ]);
        session()->save();

        $this->redirect(route('typing.result'), navigate: true);
    }

    public function render()
    {
        return view('livewire.typing-engine');
    }
}