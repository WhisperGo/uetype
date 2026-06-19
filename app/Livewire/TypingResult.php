<?php

namespace App\Livewire;

use Livewire\Component;

class TypingResult extends Component
{
    public $wpm;
    public $rawWpm;
    public $accuracy;
    public $time;
    public $mode;
    public $subMode;
    public $totalKeystrokes;
    public $correctKeystrokes;
    public $incorrectKeystrokes;
    public $wpmHistory;
    public $rawHistory;
    public $missedChars;

    public function mount()
    {
        $result = session('typing_result');
        
        if (!$result) {
            return redirect()->to('/typing');
        }

        $this->wpm = $result['wpm'];
        $this->rawWpm = $result['rawWpm'] ?? 0;
        $this->accuracy = $result['accuracy'];
        $this->time = $result['time'];
        $this->mode = $result['mode'] ?? 'time';
        $this->subMode = $result['subMode'] ?? '30';
        $this->totalKeystrokes = $result['totalKeystrokes'] ?? 0;
        $this->correctKeystrokes = $result['correctKeystrokes'] ?? 0;
        $this->incorrectKeystrokes = $result['incorrectKeystrokes'] ?? 0;
        $this->wpmHistory = $result['wpmHistory'] ?? [];
        $this->rawHistory = $result['rawHistory'] ?? [];
        $this->missedChars = $result['missedChars'] ?? [];
    }

    public function render()
    {
        return view('livewire.typing-result');
    }
}
