<?php

namespace App\Livewire;

use Livewire\Component;
use App\Models\Text;

class TypingEngine extends Component
{
    public $textToType;
    public $textId;

    public function mount()
    {
        // Ambil satu teks acak dari database (atau dummy jika database kosong)
        $text = Text::inRandomOrder('')->first();
        
        if ($text) {
            $this->textToType = $text->content;
        } else {
            // Teks cadangan jika DB kosong
            $this->textToType = "database teks anda masih kosong silakan cek seeder anda";
        }
        $this->textToType = $text ? $text->content : "ue type adalah platform kompetisi mengetik buatan kami yang sangat cepat";
        $this->textId = $text ? $text->id : null;
    }


    public function saveResult($wpm, $accuracy)
    {
        dd("Hasil Tersimpan: WPM $wpm, Akurasi $accuracy%");
    }

    public function render()
    {
        return view('livewire.typing-engine');
    }
}