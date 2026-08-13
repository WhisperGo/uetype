<?php

namespace App\Services;

use App\Enums\ClanWarStatus;
use App\Models\ClanWarModeClaim;
use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Applies every downstream effect of trusting existing probation results. */
class TypingResultPromoter
{
    /**
     * @param  list<int>  $resultIds
     * @return list<int> ids that moved from pending to clear in this call
     */
    public function promote(array $resultIds): array
    {
        $ids = array_values(array_unique(array_map('intval', $resultIds)));

        if ($ids === []) {
            return [];
        }

        return DB::transaction(function () use ($ids) {
            $rows = TypingResult::query()
                ->whereIn('id', $ids)
                ->pendingVerification()
                ->lockForUpdate()
                ->get(['id', 'user_id']);

            $promotedIds = $rows->pluck('id')->map(fn ($id) => (int) $id)->all();

            if ($promotedIds === []) {
                return [];
            }

            TypingResult::query()->whereIn('id', $promotedIds)->update([
                'review_status' => TypingResult::REVIEW_CLEAR,
                'review_reason' => null,
                'review_resolved_at' => now(),
            ]);

            foreach ($rows->pluck('user_id')->unique() as $userId) {
                $user = User::find($userId);

                if (! $user) {
                    continue;
                }

                $highest = (float) TypingResult::query()
                    ->where('user_id', $userId)
                    ->whereIn('mode', ['time', 'words'])
                    ->trustworthy()
                    ->max('net_wpm');

                $user->update(['highest_wpm' => $highest]);
                app(AchievementService::class)->syncUnlocks($user->fresh());
            }

            $this->releaseWarPoints($promotedIds);

            return $promotedIds;
        }, 3);
    }

    /** @param list<int> $resultIds */
    private function releaseWarPoints(array $resultIds): void
    {
        ClanWarModeClaim::query()
            ->with(['typingResult', 'war'])
            ->whereIn('typing_result_id', $resultIds)
            ->get()
            ->each(function (ClanWarModeClaim $claim) {
                if (! $claim->typingResult?->isTrustworthy()
                    || $claim->war?->status !== ClanWarStatus::Ongoing) {
                    return;
                }

                $duration = app(ClanWarAttempt::class)
                    ->scoredDuration($claim, (float) $claim->typingResult->duration_seconds);
                $points = ClanWarScorer::score(
                    $claim->mode,
                    $claim->mode_config,
                    $claim->typingResult,
                    $duration,
                );

                ClanWarModeClaim::where('id', $claim->id)->update(['points' => $points]);
            });
    }
}
