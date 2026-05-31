<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Text;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Auth;
use App\Models\Matches;
use App\Models\MatchParticipant;

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
        $this->dispatch('mode-changed', 
            text: $this->textToType,
            main: $this->mainMode,
            sub: $this->subMode
        );
    }

    public function restart()
    {
        $this->generateText(); // Ambil teks baru berdasarkan mainMode & subMode yang ada

        $this->dispatch('mode-changed',
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
                    $limit = ($this->mainMode === 'words') ? (int)$this->subMode : 350;
                    
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

    public function saveResult($wpm, $accuracy, $time, $totalKeystrokes, $correctKeystrokes, $wpmHistory = [], $rawHistory = [], $missedChars = [])
    {
        if (Auth::check()) {
            $user = Auth::user();
            
            // 1. Buat record di tabel Matches
            $match = Matches::create([
                'match_type' => 'solo_practice',
                'status' => 'completed',
                'mode_played' => $this->mainMode === 'quote' ? 'quote' : 'wordlist',
                'started_at' => now()->subSeconds($time),
                'ended_at' => now(),
            ]);

            // 2. Buat record di tabel MatchParticipants
            MatchParticipant::create([
                'match_id' => $match->id,
                'user_id' => $user->id,
                'wpm' => $wpm,
                'accuracy' => $accuracy,
                'placement' => 1,
            ]);

            // 3. Update User stats
            $gainedXp = round($wpm * ($accuracy / 100));
            $gainedCoins = round($wpm / 2);

            $user->xp += $gainedXp;
            $user->coins += $gainedCoins;

            if ($wpm > $user->highest_wpm) {
                $user->highest_wpm = $wpm;
            }

            $user->save();
        }

        session()->put('typing_result', [
            'wpm' => $wpm,
            'accuracy' => $accuracy,
            'time' => $time,
            'mode' => $this->mainMode,
            'subMode' => $this->subMode,
            'totalKeystrokes' => $totalKeystrokes,
            'correctKeystrokes' => $correctKeystrokes,
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