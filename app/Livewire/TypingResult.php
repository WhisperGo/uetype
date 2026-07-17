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

    /** Stream error ringkas dari session: [{second, index, actual}]. Di-enrich saat render. */
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
        // ?? WAJIB: sesi lama (dari request sebelum deploy) tak punya kunci ini.
        $this->errorEvents = $result['errorEvents'] ?? [];
    }

    /**
     * View model penanda error: KAPAN (detik), TUTS MANA, DI KATA MANA + apa yang
     * benar-benar ditekan.
     *
     * #[Computed], BUKAN properti publik: hasil enrich-nya berkali lipat lebih besar
     * dari errorEvents mentah (tiap event membawa string kata), dan properti publik ikut
     * di-serialize ke snapshot Livewire di SETIAP request — padahal ini cuma dibutuhkan
     * saat render. Lazy juga: cabang survival tak pernah menyentuhnya.
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
     * Ulang tantangan yang sama persis (hanya mode words): titipkan teks + mode sesi
     * ini ke session lalu kembali ke /typing, yang akan memakainya sekali pakai
     * ketimbang merakit teks acak baru. Berbeda dari "Next Test" yang selalu acak.
     */
    public function retry()
    {
        // Full-load (TANPA navigate:true): masuk /typing via SPA lalu Back akan me-restore
        // snapshot mesin ketik yang rusak. Muat penuh menjaga /typing selalu mount bersih.
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
