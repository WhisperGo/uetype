<?php

namespace App\Livewire;

use App\Services\TypingErrorInspector;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * The post-session result screen for a solo run: displays the final wpm, raw wpm,
 * accuracy, time and mode. A presentational component fed by TypingEngine.
 */
class TypingResult extends Component
{
    public $wpm;

    public $rawWpm;

    public $accuracy;

    public $time;

    public $mode;

    public $subMode;

    public $score;

    public $totalKeystrokes;

    public $correctKeystrokes;

    public $incorrectKeystrokes;

    public $wpmHistory;

    public $rawHistory;

    public $missedChars;

    public $xpEarned;

    public $isPersonalBest;

    public $previousBest;

    public $consistency;

    public $levelData;

    public $drainEventCount;

    public $survivalPreviousBest;

    public $isSurvivalPersonalBest;

    public $ghostResult;

    public $textToType;

    /** Compact error stream from the session: [{second, index, actual}]. Enriched at render time. */
    public $errorEvents;

    public function mount()
    {
        $result = session('typing_result');

        if (! $result) {
            return redirect()->to('/typing');
        }

        $this->wpm = $result['wpm'];
        $this->rawWpm = $result['rawWpm'] ?? 0;
        $this->accuracy = $result['accuracy'];
        $this->time = $result['time'];
        $this->mode = $result['mode'] ?? 'time';
        $this->subMode = $result['subMode'] ?? '30';
        $this->score = $result['score'] ?? null;
        $this->totalKeystrokes = $result['totalKeystrokes'] ?? 0;
        $this->correctKeystrokes = $result['correctKeystrokes'] ?? 0;
        $this->incorrectKeystrokes = $result['incorrectKeystrokes'] ?? 0;
        $this->wpmHistory = $result['wpmHistory'] ?? [];
        $this->rawHistory = $result['rawHistory'] ?? [];
        $this->missedChars = $result['missedChars'] ?? [];
        $this->xpEarned = $result['xpEarned'] ?? 0;
        $this->isPersonalBest = $result['isPersonalBest'] ?? false;
        $this->previousBest = $result['previousBest'] ?? null;
        $this->consistency = $result['consistency'] ?? null;
        $this->levelData = $result['levelData'] ?? null;
        $this->drainEventCount = $result['drainEventCount'] ?? 0;
        $this->survivalPreviousBest = $result['survivalPreviousBest'] ?? null;
        $this->isSurvivalPersonalBest = $result['isSurvivalPersonalBest'] ?? false;
        $this->ghostResult = $result['ghostResult'] ?? null;
        $this->textToType = $result['textToType'] ?? null;
        // ?? REQUIRED: old sessions (from before this deploy) don't have this key.
        $this->errorEvents = $result['errorEvents'] ?? [];
    }

    /**
     * Error-marker view model: WHEN (seconds), WHICH KEY, IN WHICH WORD + what was
     * actually pressed.
     *
     * #[Computed], NOT a public property: its enriched output is many times larger than
     * the raw errorEvents (each event carries a word string), and public properties are
     * serialized into the Livewire snapshot on EVERY request — yet this is only needed at
     * render time. Lazy too: the survival branch never touches it.
     */
    #[Computed]
    public function errorSeries(): array
    {
        return TypingErrorInspector::inspect(
            $this->errorEvents ?? [],
            $this->textToType,
            count($this->wpmHistory ?? []),
        );
    }

    /**
     * Retry the exact same challenge (words mode only): stash this session's text + mode
     * in the session then return to /typing, which uses it once instead of assembling
     * fresh random text. Differs from "Next Test", which is always random.
     */
    public function retry()
    {
        // Full load (WITHOUT navigate:true): entering /typing via SPA then Back would
        // restore a broken typing-engine snapshot. A full load keeps /typing mounting clean.
        if ($this->mode !== 'words' || ! $this->textToType) {
            return $this->redirect(route('typing'));
        }

        session()->put('typing_retry', [
            'text' => $this->textToType,
            'mode' => $this->mode,
            'subMode' => $this->subMode,
        ]);

        return $this->redirect(route('typing'));
    }

    public function render()
    {
        return view('livewire.typing-result');
    }
}
