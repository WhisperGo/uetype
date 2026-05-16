<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Text;

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
        // Logika pengambilan teks berdasarkan mode
        $query = Text::query();

        if ($this->mainMode === 'quote') {
            $query->where('mode', 'quote');
            // Tambahkan logika difficulty/length di sini nanti
        } else {
            $query->where('mode', 'wordlist');
        }

        $text = $query->inRandomOrder()->first();
        
        // Jika mode 'words', kita potong jumlah katanya sesuai subMode
        if ($this->mainMode === 'words' && $text) {
            $words = explode(' ', $text->content);
            $this->textToType = implode(' ', array_slice($words, 0, (int)$this->subMode));
        } else {
            $this->textToType = $text ? $text->content : "siapkan jemari anda untuk tantangan uetype";
        }
    }

    public function render()
    {
        return view('livewire.typing-engine');
    }
}