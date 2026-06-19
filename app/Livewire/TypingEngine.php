<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Text;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use App\Models\Matches;
use App\Models\MatchParticipant;
use App\Services\AntiCheatService;

class TypingEngine extends Component
{
    // Mode Utama: 'time', 'words', 'quote'
    public $mainMode = 'time';

    // Sub Mode (Pilihan angka/panjang)
    public $subMode = '30';

    public $textToType;

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
        } else {
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

                    // Jika mode time, kita berikan 500 kata (cukup untuk tes 120 detik, namun jauh lebih ringan untuk performa browser)
                    // Jika mode words, kita berikan sesuai jumlah yang dipilih
                    $limit = ($this->mainMode === 'words') ? (int) $this->subMode : 350;

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

    public function saveResult($wpm, $accuracy, $time, $totalKeystrokes, $correctKeystrokes, $wpmHistory = [], $rawHistory = [], $missedChars = [], $keystrokeTimings = [])
    {
        // Anti-cheat: server HITUNG ULANG wpm/akurasi dari timing keystroke,
        // deteksi pola tak-manusiawi, lalu pakai angka hasil server (bukan client).
        $verdict = app(AntiCheatService::class)->analyze(
            is_array($keystrokeTimings) ? $keystrokeTimings : [],
            (int) $correctKeystrokes,
            (int) $totalKeystrokes,
            $wpm,
        );

        // Jika timing tak cukup untuk rekalkulasi (mis. <2 keystroke), jatuh ke angka client.
        $finalWpm = $verdict['wpm'] > 0 ? $verdict['wpm'] : (float) $wpm;
        $finalAccuracy = $verdict['accuracy'] > 0 ? $verdict['accuracy'] : (float) $accuracy;
        $isSuspicious = $verdict['is_suspicious'];
        $cheatSummary = $verdict['cheat_summary'];

        if (Auth::check()) {
            $user = Auth::user();

            DB::transaction(function () use ($user, $time, $finalWpm, $finalAccuracy, $isSuspicious, $cheatSummary, $wpmHistory, $rawHistory, $missedChars) {
                // 1. Buat record di tabel Matches
                $match = Matches::create([
                    'match_type' => 'solo',
                    'is_ranked' => false,
                    'mode_played' => $this->mainMode, // 'time' | 'words' | 'quote'
                    'mode_config' => $this->mainMode === 'quote' ? null : (int) $this->subMode,
                    'status' => 'completed',
                    'started_at' => now()->subSeconds($time),
                    'ended_at' => now(),
                ]);

                // 2. Buat record di tabel MatchParticipants (analitik + verdict anti-cheat menyatu)
                MatchParticipant::create([
                    'match_id' => $match->id,
                    'user_id' => $user->id,
                    'wpm' => $finalWpm,
                    'accuracy' => $finalAccuracy,
                    'placement' => 1,
                    'connection_status' => 'connected',
                    'wpm_samples' => [
                        'wpm' => $wpmHistory,
                        'raw' => $rawHistory,
                    ],
                    'heatmap_data' => $missedChars,
                    'is_suspicious' => $isSuspicious,
                    'cheat_summary' => $cheatSummary,
                ]);

                // 3. Update User stats (XP/coins dari hasil tervalidasi server)
                $user->xp += (int) round($finalWpm * ($finalAccuracy / 100));
                $user->coins += (int) round($finalWpm / 2);

                // Papan WPM solo HANYA naik dari hasil yang TIDAK mencurigakan (blueprint 5.2).
                if (!$isSuspicious && $finalWpm > $user->highest_wpm) {
                    $user->highest_wpm = $finalWpm;
                }

                $user->save();
            });
        }

        session()->put('typing_result', [
            'wpm' => $finalWpm,
            'accuracy' => $finalAccuracy,
            'time' => $time,
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
            'totalKeystrokes' => $totalKeystrokes,
            'correctKeystrokes' => $correctKeystrokes,
            'wpmHistory' => $wpmHistory,
            'rawHistory' => $rawHistory,
            'missedChars' => $missedChars,
            'is_suspicious' => $isSuspicious,
        ]);
        session()->save();

        $this->redirect(route('typing.result'), navigate: true);
    }

    public function render()
    {
        return view('livewire.typing-engine');
    }
}