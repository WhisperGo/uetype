<?php

namespace App\Services;

use App\Models\TypingResult;
use App\Models\TypingSpeedCapability;
use App\Models\User;

class TypingSpeedCapabilityService
{
    public const RULE_VERSION = 2;

    /** Version 1 used the stronger 30-second challenge and remains valid. */
    private const SUPPORTED_RULE_VERSIONS = [1, self::RULE_VERSION];

    private const ALLOWANCE_FRACTION = 0.15;

    private const MIN_ALLOWANCE_WPM = 15.0;

    public function ceiling(float $verifiedWpm): float
    {
        return round($verifiedWpm + max(self::MIN_ALLOWANCE_WPM, $verifiedWpm * self::ALLOWANCE_FRACTION), 2);
    }

    public function supports(int $userId, string $language, float $wpm, ?array $timing = null): bool
    {
        if ($timing === null
            || ($timing['has_data'] ?? false) !== true
            || ! empty($timing['reasons'] ?? [])) {
            return false;
        }

        $capability = TypingSpeedCapability::query()
            ->where('user_id', $userId)
            ->where('language', $language)
            ->whereIn('rule_version', self::SUPPORTED_RULE_VERSIONS)
            ->first();

        return $capability !== null
            && $wpm <= $this->ceiling((float) $capability->verified_wpm);
    }

    /** @return array{capability:TypingSpeedCapability,promoted_ids:list<int>,ceiling:float} */
    public function recordAndPromote(User $user, string $language, float $wpm, float $accuracy, array $evidence): array
    {
        $existing = TypingSpeedCapability::query()
            ->where('user_id', $user->id)
            ->where('language', $language)
            ->first();

        $effectiveWpm = max($wpm, (float) ($existing?->verified_wpm ?? 0));
        $effectiveAccuracy = $effectiveWpm === $wpm
            ? $accuracy
            : (float) $existing->verified_accuracy;

        $capability = TypingSpeedCapability::updateOrCreate(
            ['user_id' => $user->id, 'language' => $language],
            [
                'verified_wpm' => $effectiveWpm,
                'verified_accuracy' => $effectiveAccuracy,
                'verified_at' => now(),
                'rule_version' => self::RULE_VERSION,
                'evidence_meta' => $evidence,
            ],
        );

        $ceiling = $this->ceiling((float) $capability->verified_wpm);
        $ids = TypingResult::query()
            ->where('user_id', $user->id)
            ->where('language', $language)
            ->whereIn('mode', ['time', 'words'])
            ->pendingVerification()
            ->whereIn('review_reason', ['no_history_high', 'longitudinal_spike'])
            ->where('net_wpm', '<=', $ceiling)
            ->get()
            ->filter(function (TypingResult $result) {
                $reasons = data_get($result->integrity_meta, 'timing.reasons', []);

                return empty($reasons);
            })
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        return [
            'capability' => $capability,
            'promoted_ids' => app(TypingResultPromoter::class)->promote($ids),
            'ceiling' => $ceiling,
        ];
    }
}
