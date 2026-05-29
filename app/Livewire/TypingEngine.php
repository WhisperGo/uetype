<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Text;
use Illuminate\Support\Facades\File;

class TypingEngine extends Component
{
    // Mode Utama: 'time', 'words', 'quote'
    public $mainMode = 'time'; 
    
    // Sub Mode (Pilihan angka/panjang)
    public $subMode = '30'; 

    public $textToType;

    public function mount()
    {
        $this->generateText();
    }

    // Fungsi untuk mengganti mode dan ambil teks baru
    public function setMode($main, $sub)
    {
        $this->mainMode = $main;
        $this->subMode = $sub;
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
                    
                    // Jika mode time, kita berikan 100 kata (atau cukup banyak agar tidak habis)
                    // Jika mode words, kita berikan sesuai jumlah yang dipilih
                    $limit = ($this->mainMode === 'words') ? (int)$this->subMode : 100;
                    $selectedWords = array_slice($wordsArray, 0, $limit);
                    
                    $this->textToType = implode(' ', $selectedWords);
                } else {
                    $this->textToType = "error: struktur file json tidak valid";
                }
            } else {
                $this->textToType = "error: file wordlist tidak ditemukan";
            }
        }
    }

    public function render()
    {
        return view('livewire.typing-engine');
    }
}