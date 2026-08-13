<?php

namespace App\Services;

use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a corroborated Time/Words probation cluster into trusted results.
 *
 * Repetition alone is deliberately insufficient. Every promoted result must come from a
 * unique server-issued session and carry a clean, sufficiently large timing sample. The
 * cluster must also contain enough real typing volume and remain internally plausible.
 */
class AutomaticResultResolver
{
    private const WINDOW_DAYS = 14;

    private const MIN_RESULTS = 3;

    private const MIN_TOTAL_SECONDS = 60.0;

    private const MIN_TOTAL_CORRECT_CHARS = 1200;

    private const MAX_DISTANCE_FROM_MEDIAN_FRACTION = 0.12;

    private const MIN_ABSOLUTE_DISTANCE_WPM = 12.0;

    /**
     * @return array{promoted_count: int, current_cleared: bool, promoted_ids: list<int>}
     */
    public function resolveFor(TypingResult $result): array
    {
        if ($result->review_status !== TypingResult::REVIEW_PENDING
            || ! in_array($this->modeValue($result), ['time', 'words'], true)) {
            return $this->emptyResult($result);
        }

        return DB::transaction(function () use ($result) {
            /** @var Collection<int, TypingResult> $candidates */
            $candidates = TypingResult::query()
                ->where('user_id', $result->user_id)
                ->where('mode', $this->modeValue($result))
                ->where('mode_config', $result->mode_config)
                ->where('language', $result->language)
                ->pendingVerification()
                ->whereIn('review_reason', ['no_history_high', 'longitudinal_spike'])
                ->whereNotNull('session_fingerprint')
                ->whereNotNull('integrity_meta')
                ->where('created_at', '>=', now()->subDays(self::WINDOW_DAYS))
                ->orderByDesc('id')
                ->limit(20)
                ->lockForUpdate()
                ->get();

            $cluster = $this->corroboratedCluster($candidates);

            if ($cluster === null) {
                return $this->emptyResult($result);
            }

            $wpms = $cluster->pluck('net_wpm')->map(fn ($value) => (float) $value)->sort()->values();
            $middle = intdiv($wpms->count(), 2);
            $median = $wpms->count() % 2
                ? $wpms[$middle]
                : ($wpms[$middle - 1] + $wpms[$middle]) / 2;
            $allowedDistance = max(self::MIN_ABSOLUTE_DISTANCE_WPM, $median * self::MAX_DISTANCE_FROM_MEDIAN_FRACTION);

            // Once strong new evidence establishes the cluster, it may also rehabilitate an
            // older pending row from before evidence metadata existed, provided that row is in
            // the same exact bucket and sits inside the corroborated range.
            $ids = TypingResult::query()
                ->where('user_id', $result->user_id)
                ->where('mode', $this->modeValue($result))
                ->where('mode_config', $result->mode_config)
                ->where('language', $result->language)
                ->pendingVerification()
                ->whereIn('review_reason', ['no_history_high', 'longitudinal_spike'])
                ->whereBetween('net_wpm', [$median - $allowedDistance, $median + $allowedDistance])
                ->lockForUpdate()
                ->pluck('id')
                ->map(fn ($id) => (int) $id)
                ->all();

            $promotedIds = app(TypingResultPromoter::class)->promote($ids);

            return [
                'promoted_count' => count($promotedIds),
                'current_cleared' => in_array((int) $result->id, $promotedIds, true),
                'promoted_ids' => $promotedIds,
            ];
        }, 3);
    }

    /** Re-run every eligible pending cluster, used by the scheduled safety net. */
    public function reconcile(?int $userId = null): int
    {
        $query = TypingResult::query()
            ->pendingVerification()
            ->whereIn('mode', ['time', 'words'])
            ->whereIn('review_reason', ['no_history_high', 'longitudinal_spike'])
            ->orderBy('id');

        if ($userId !== null) {
            $query->where('user_id', $userId);
        }

        $promoted = 0;

        $query->chunkById(100, function ($rows) use (&$promoted) {
            foreach ($rows as $row) {
                $promoted += $this->resolveFor($row)['promoted_count'];
            }
        });

        return $promoted;
    }

    /** Reconcile probation rows attached to a war immediately before settlement. */
    public function reconcileWar(int $warId): int
    {
        $resultIds = ClanWarModeClaim::query()
            ->where('clan_war_id', $warId)
            ->whereNotNull('typing_result_id')
            ->pluck('typing_result_id');

        $promoted = 0;

        TypingResult::query()
            ->whereIn('id', $resultIds)
            ->pendingVerification()
            ->get()
            ->each(function (TypingResult $result) use (&$promoted) {
                $promoted += $this->resolveFor($result)['promoted_count'];
            });

        // Legacy pending claims may already carry points from before probation-aware
        // attachment existed. Settlement must neutralise those stale numbers.
        ClanWarModeClaim::query()
            ->where('clan_war_id', $warId)
            ->whereHas('typingResult', fn ($query) => $query->pendingVerification())
            ->update(['points' => 0]);

        return $promoted;
    }

    /**
     * Pick the strongest coherent subset instead of letting one unusable/outlier pending row
     * block every later honest run in the same bucket. A row without timing evidence cannot
     * vote for the cluster; once capability is independently established, the promotion query
     * below may still rehabilitate it when its WPM is inside the corroborated range.
     *
     * @param  Collection<int, TypingResult>  $rows
     * @return Collection<int, TypingResult>|null
     */
    private function corroboratedCluster(Collection $rows): ?Collection
    {
        $eligible = $rows->filter(fn (TypingResult $row) => $this->isEligibleEvidence($row));

        if ($eligible->count() < self::MIN_RESULTS) {
            return null;
        }

        $best = collect();

        foreach ($eligible as $pivot) {
            $pivotWpm = (float) $pivot->net_wpm;
            $radius = max(self::MIN_ABSOLUTE_DISTANCE_WPM, $pivotWpm * self::MAX_DISTANCE_FROM_MEDIAN_FRACTION);
            $nearby = $eligible->filter(
                fn (TypingResult $row) => abs((float) $row->net_wpm - $pivotWpm) <= $radius
            );

            if ($nearby->count() > $best->count()) {
                $best = $nearby;
            }
        }

        if ($best->count() < self::MIN_RESULTS) {
            return null;
        }

        $totalSeconds = (float) $best->sum(fn (TypingResult $row) => (float) $row->duration_seconds);
        $totalCorrect = (int) $best->sum('correct_chars');

        if ($totalSeconds < self::MIN_TOTAL_SECONDS && $totalCorrect < self::MIN_TOTAL_CORRECT_CHARS) {
            return null;
        }

        $wpms = $best->pluck('net_wpm')->map(fn ($value) => (float) $value)->sort()->values();
        $middle = intdiv($wpms->count(), 2);
        $median = $wpms->count() % 2
            ? $wpms[$middle]
            : ($wpms[$middle - 1] + $wpms[$middle]) / 2;
        $allowedDistance = max(self::MIN_ABSOLUTE_DISTANCE_WPM, $median * self::MAX_DISTANCE_FROM_MEDIAN_FRACTION);

        return $wpms->every(fn (float $wpm) => abs($wpm - $median) <= $allowedDistance)
            ? $best
            : null;
    }

    private function isEligibleEvidence(TypingResult $row): bool
    {
        $meta = $row->integrity_meta ?? [];
        $timing = $meta['timing'] ?? [];

        if (($timing['has_data'] ?? false) !== true || ! empty($timing['reasons'] ?? [])) {
            return false;
        }

        $sampleCount = (int) ($timing['sample_count'] ?? 0);
        $claimedKeystrokes = (int) ($timing['claimed_keystrokes'] ?? 0);

        // Up to 300 intervals are reservoir-sampled. On shorter runs most keystrokes
        // should be represented; a tiny hand-picked sample cannot unlock trust.
        if ($sampleCount < min(100, max(20, (int) floor($claimedKeystrokes * 0.50)))) {
            return false;
        }

        return ($meta['server_session_confirmed'] ?? false) === true
            && (($meta['consistency'] ?? null) === null || (int) $meta['consistency'] < 99);
    }

    /** @return array{promoted_count: int, current_cleared: bool, promoted_ids: list<int>} */
    private function emptyResult(TypingResult $result): array
    {
        return [
            'promoted_count' => 0,
            'current_cleared' => $result->review_status === TypingResult::REVIEW_CLEAR,
            'promoted_ids' => [],
        ];
    }

    private function modeValue(TypingResult $result): string
    {
        return $result->mode instanceof \BackedEnum ? $result->mode->value : (string) $result->mode;
    }
}
