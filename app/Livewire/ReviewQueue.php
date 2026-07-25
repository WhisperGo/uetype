<?php

namespace App\Livewire;

use App\Models\TypingResult;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Component;

/**
 * Admin review queue for flagged solo results (anti-cheat report §7.6).
 *
 * Results held as `pending` by the longitudinal baseline (§7.5) wait here. An admin can
 * APPROVE (the result rejoins the leaderboard and advances the player's PB) or REJECT (it
 * stays out for good). This is the one anti-cheat layer that can't be paced from below,
 * because there is no fixed number to aim just under -- a human decides.
 *
 * Access is admin-only: the route carries EnsureUserIsAdmin, and every action re-checks the
 * `access-monitoring` gate server-side so a non-admin can't drive it by forging requests.
 */
#[Layout('layouts.app')]
class ReviewQueue extends Component
{
    /** Guard every state-changing action, not just the page load. */
    private function authorizeAdmin(): void
    {
        abort_unless(Gate::allows('access-monitoring'), 404);
    }

    public function mount(): void
    {
        $this->authorizeAdmin();
    }

    /** Approve a pending result: it rejoins the board and can advance the player's PB. */
    public function approve(int $id): void
    {
        $this->authorizeAdmin();

        $result = TypingResult::where('id', $id)
            ->where('review_status', TypingResult::REVIEW_PENDING)
            ->first();

        if (! $result) {
            return;
        }

        $result->update(['review_status' => TypingResult::REVIEW_APPROVED]);

        // Approving may make this the player's new PB, which was withheld while pending.
        $user = $result->user;
        if ($user && $result->mode->value !== 'survival' && (float) $result->net_wpm > (float) $user->highest_wpm) {
            $user->highest_wpm = $result->net_wpm;
            $user->save();
        }

        unset($this->pending);
    }

    /** Reject a pending result: it stays off the leaderboard permanently. */
    public function reject(int $id): void
    {
        $this->authorizeAdmin();

        TypingResult::where('id', $id)
            ->where('review_status', TypingResult::REVIEW_PENDING)
            ->update(['review_status' => TypingResult::REVIEW_REJECTED]);

        unset($this->pending);
    }

    /** Pending results, newest first, with the flagged player. */
    #[Computed]
    public function pending()
    {
        return TypingResult::with('user')
            ->where('review_status', TypingResult::REVIEW_PENDING)
            ->orderByDesc('id')
            ->limit(100)
            ->get();
    }

    public function render()
    {
        return view('livewire.review-queue');
    }
}
