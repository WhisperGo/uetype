<?php

namespace App\Services;

use App\Models\TypingResult;
use App\Support\LongitudinalDecision;
use App\Support\TypingLanguage;

/** Explainable, robust per-player baseline for solo Time and Words results. */
class LongitudinalBaseline
{
    private const HISTORY_WINDOW = 20;

    private const MIN_EXACT_HISTORY = 5;

    private const MIN_RELATED_HISTORY = 3;

    private const SPIKE_FRACTION = 0.40;

    private const NO_HISTORY_WPM = 150.0;

    private const MIN_ABSOLUTE_HEADROOM = 20.0;

    private const MAD_MULTIPLIER = 4.0;

    /**
     * Evaluate a new result without letting the result compare against itself.
     */
    public function decisionFor(
        int $userId,
        string $mode,
        string $modeConfig,
        string $language,
        float $netWpm,
        ?array $timing = null,
    ): LongitudinalDecision {
        $language = TypingLanguage::resolve($language);

        if (app(TypingSpeedCapabilityService::class)->supports(
            $userId,
            $language,
            $netWpm,
            $timing,
        )) {
            return new LongitudinalDecision(null, 'verified_capability', [
                'source_scope' => 'verified_capability',
            ]);
        }

        $exact = $this->trustedQuery($userId, $mode, $language)
            ->where('mode_config', $modeConfig)
            ->limit(self::HISTORY_WINDOW)
            ->pluck('net_wpm')
            ->map(fn ($value) => (float) $value)
            ->all();

        if (count($exact) >= self::MIN_EXACT_HISTORY) {
            return $this->against($exact, $netWpm, 'established', 'exact');
        }

        // A player established in a comparable config is not a true no-history player.
        // This is supporting evidence only: it can clear an in-family pace, never legitimise
        // a second large jump above the related distribution.
        $related = $this->trustedQuery($userId, $mode, $language)
            ->where('mode_config', '!=', $modeConfig)
            ->limit(self::HISTORY_WINDOW)
            ->pluck('net_wpm')
            ->map(fn ($value) => (float) $value)
            ->all();

        if (count($related) >= self::MIN_RELATED_HISTORY) {
            $decision = $this->against($related, $netWpm, 'insufficient', 'related_config');

            if (! $decision->isPending()) {
                return $decision;
            }
        }

        return new LongitudinalDecision(
            $netWpm >= self::NO_HISTORY_WPM ? 'no_history_high' : null,
            $exact === [] && $related === [] ? 'empty' : 'insufficient',
            [
                'sample_count' => count($exact),
                'related_sample_count' => count($related),
                'source_scope' => $related === [] ? 'none' : 'related_config',
                'effective_threshold' => self::NO_HISTORY_WPM,
            ],
        );
    }

    /** Backward-compatible reason-only API used by older callers/tests. */
    public function reviewReasonFor(
        int $userId,
        string $mode,
        string $modeConfig,
        float $netWpm,
        string $language = TypingLanguage::DEFAULT,
    ): ?string {
        return $this->decisionFor($userId, $mode, $modeConfig, $language, $netWpm)->reason;
    }

    private function trustedQuery(int $userId, string $mode, string $language)
    {
        return TypingResult::query()
            ->where('user_id', $userId)
            ->where('mode', $mode)
            ->where('language', $language)
            ->trustworthy()
            ->orderByDesc('id');
    }

    /**
     * @param  list<float>  $samples
     */
    private function against(array $samples, float $netWpm, string $state, string $source): LongitudinalDecision
    {
        $center = $this->median($samples);
        $deviations = array_map(fn (float $value) => abs($value - $center), $samples);
        $mad = $this->median($deviations);

        // Both conditions are intentionally conservative. A noisy player's natural spread
        // earns more headroom, while a perfectly stable baseline still gets an absolute and
        // percentage allowance. One warm-up/outlier cannot drag the median down like mean did.
        $threshold = max(
            $center * (1 + self::SPIKE_FRACTION),
            $center + self::MIN_ABSOLUTE_HEADROOM,
            $center + self::MAD_MULTIPLIER * 1.4826 * $mad,
        );

        return new LongitudinalDecision(
            $netWpm > $threshold ? 'longitudinal_spike' : null,
            $state,
            [
                'sample_count' => count($samples),
                'center' => round($center, 2),
                'spread' => round($mad, 2),
                'effective_threshold' => round($threshold, 2),
                'source_scope' => $source,
            ],
        );
    }

    /** @param list<float> $values */
    private function median(array $values): float
    {
        sort($values, SORT_NUMERIC);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1
            ? (float) $values[$middle]
            : ((float) $values[$middle - 1] + (float) $values[$middle]) / 2;
    }
}
