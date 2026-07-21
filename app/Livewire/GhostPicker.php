<?php

namespace App\Livewire;

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\TypingResult;
use App\Services\GhostResolver;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Ghost Mode opponent picker, coordinated with Alpine via 'ghost-selected'/'ghost-cleared'.
 * Trust boundary: the client sends only an identifier; WPM is always re-derived from the DB.
 */
class GhostPicker extends Component
{
    public string $mainMode = 'time';

    public string $subMode = '30';

    public function mount(string $mainMode, string $subMode): void
    {
        $this->mainMode = $mainMode;
        $this->subMode = $subMode;
    }

    /** Ghost Mode only applies to Time & Words. */
    private function isEligibleMode(): bool
    {
        return in_array($this->mainMode, ['time', 'words'], true);
    }

    /** The current user's own best WPM (for the "race yourself" option). */
    public function getMyBestProperty(): float
    {
        return (float) (Auth::user()->highest_wpm ?? 0);
    }

    /**
     * Accepted friends with highest_wpm > 0 only; those who've never played are hidden.
     *
     * @return Collection<int, array{friendship_id:int, user_id:int, username:string, highest_wpm:float}>
     */
    public function getEligibleFriendsProperty()
    {
        $me = Auth::id();

        return Friendship::with(['requester', 'addressee'])
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $me)->orWhere('addressee_id', $me))
            ->get()
            ->map(function (Friendship $f) use ($me) {
                $friend = $f->requester_id === $me ? $f->addressee : $f->requester;

                return $friend ? [
                    'friendship_id' => $f->id,
                    'user_id' => $friend->id,
                    'username' => $friend->username,
                    'highest_wpm' => (float) $friend->highest_wpm,
                ] : null;
            })
            ->filter(fn ($row) => $row !== null && $row['highest_wpm'] > 0)
            ->sortByDesc('highest_wpm')
            ->values();
    }

    /**
     * Top leaderboard entries for TypingEngine's active mode/config. Ghost is time/words
     * only, so the metric is always net_wpm.
     *
     * @return Collection<int, array{user_id:int, username:string, wpm:float, accuracy:float}>
     */
    public function getEligibleLeaderboardProperty()
    {
        if (! $this->isEligibleMode()) {
            return collect();
        }

        $subQuery = TypingResult::select('user_id', DB::raw('MAX(net_wpm) as best_score'))
            ->where('mode', $this->mainMode)
            ->where('mode_config', $this->subMode)
            ->groupBy('user_id');

        // GROUP BY in the outer query is required: the join matches `tr.net_wpm =
        // pb.best_score`, so a user with TWO results of identical net_wpm would appear
        // twice in the ghost opponent list (pushing other candidates out of the top 10).
        return TypingResult::from('typing_results as tr')
            ->joinSub($subQuery, 'pb', function ($join) {
                $join->on('tr.user_id', '=', 'pb.user_id')
                    ->on('tr.net_wpm', '=', 'pb.best_score');
            })
            ->join('users', 'tr.user_id', '=', 'users.id')
            ->groupBy('users.id', 'users.username', 'pb.best_score')
            ->select(
                'users.id as user_id',
                'users.username',
                DB::raw('pb.best_score as wpm'),
                DB::raw('MAX(tr.accuracy) as accuracy'),
            )
            ->where('pb.best_score', '>', 0)
            ->orderBy('wpm', 'desc')
            ->limit(10)
            ->get()
            ->map(fn ($row) => [
                'user_id' => (int) $row->user_id,
                'username' => $row->username,
                'wpm' => (float) $row->wpm,
                'accuracy' => (float) $row->accuracy,
            ]);
    }

    /**
     * Select a ghost opponent. $refId is interpreted per $type:
     *   'own'         -> ignored, uses Auth::user() directly.
     *   'friend'      -> $refId = friendship_id (ownership validated).
     *   'leaderboard' -> $refId = target user_id (re-scoped to the active mode/config).
     * WPM is always re-derived from the DB, never from the client.
     */
    public function selectOpponent(string $type, ?int $refId = null): void
    {
        // Ghost is locked for guests (the leaderboard is closed to guests). The picker
        // isn't mounted for guests anyway; this is second-line defense at the endpoint.
        if (! Auth::check()) {
            return;
        }

        if (! $this->isEligibleMode()) {
            return;
        }

        // WPM/label are derived from the DB via the resolver (one source of truth, also
        // used when restoring a selection). An invalid identity -> null -> a no-op.
        $ghost = app(GhostResolver::class)->resolve($type, $refId, $this->mainMode, $this->subMode, Auth::id());

        if ($ghost === null) {
            return;
        }

        // Store the IDENTITY (not the wpm number) so the selection persists across tests /
        // reloads and can be re-derived. Read back by TypingEngine::applyGhostRestore().
        session()->put('ghost_selection', ['type' => $type, 'ref_id' => $refId]);

        $this->dispatch('ghost-selected', type: $type, wpm: $ghost['wpm'], label: $ghost['label']);
        $this->dispatch('close-modal', 'ghost-picker');
    }

    /** Clear the selected ghost opponent so it won't reappear on the next test. */
    public function clearOpponent(): void
    {
        // Explicit clear: drop the stored identity so the ghost does NOT reappear on the
        // next test (different from merely hiding it when switching to survival).
        session()->forget('ghost_selection');

        $this->dispatch('ghost-cleared');
    }

    public function render()
    {
        return view('livewire.ghost-picker');
    }
}
