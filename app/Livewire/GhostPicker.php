<?php

namespace App\Livewire;

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\TypingResult;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Livewire\Component;

/**
 * Ghost Mode opponent picker. A separate component from TypingEngine, coordinated
 * with Alpine via the 'ghost-selected'/'ghost-cleared' browser events.
 *
 * Trust boundary: the client only sends an identifier (friendship_id/user_id),
 * never a WPM directly — selectOpponent() always re-derives the WPM from the DB.
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

    /** Ghost Mode hanya berlaku untuk Time & Words. */
    private function isEligibleMode(): bool
    {
        return in_array($this->mainMode, ['time', 'words'], true);
    }

    public function getMyBestProperty(): float
    {
        return (float) (Auth::user()->highest_wpm ?? 0);
    }

    /**
     * Teman accepted dengan highest_wpm > 0 saja; yang belum pernah main disembunyikan.
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
     * Top entries leaderboard untuk mode/config aktif di TypingEngine. Ghost hanya
     * time/words, jadi metric selalu net_wpm.
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

        return TypingResult::from('typing_results as tr')
            ->joinSub($subQuery, 'pb', function ($join) {
                $join->on('tr.user_id', '=', 'pb.user_id')
                    ->on('tr.net_wpm', '=', 'pb.best_score');
            })
            ->join('users', 'tr.user_id', '=', 'users.id')
            ->select('users.id as user_id', 'users.username', DB::raw('pb.best_score as wpm'), 'tr.accuracy')
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
     * Pilih lawan ghost. $refId ditafsirkan sesuai $type:
     *   'own'         -> diabaikan, pakai Auth::user() langsung.
     *   'friend'      -> $refId = friendship_id (divalidasi kepemilikan).
     *   'leaderboard' -> $refId = user_id target (di-scope ulang ke mode/config aktif).
     * WPM selalu diturunkan ulang dari DB, tidak pernah dari client.
     */
    public function selectOpponent(string $type, ?int $refId = null): void
    {
        if (! $this->isEligibleMode()) {
            return;
        }

        $wpm = null;
        $label = null;

        if ($type === 'own') {
            $wpm = (float) (Auth::user()->highest_wpm ?? 0);
            $label = 'Your Best';
        } elseif ($type === 'friend' && $refId !== null) {
            $friendship = Friendship::where('id', $refId)
                ->where('status', FriendshipStatus::Accepted)
                ->where(fn ($q) => $q->where('requester_id', Auth::id())->orWhere('addressee_id', Auth::id()))
                ->with(['requester', 'addressee'])
                ->first();

            if ($friendship) {
                $friend = $friendship->requester_id === Auth::id() ? $friendship->addressee : $friendship->requester;
                if ($friend) {
                    $wpm = (float) $friend->highest_wpm;
                    $label = $friend->username;
                }
            }
        } elseif ($type === 'leaderboard' && $refId !== null) {
            $best = TypingResult::where('user_id', $refId)
                ->where('mode', $this->mainMode)
                ->where('mode_config', $this->subMode)
                ->max('net_wpm');

            if ($best !== null && (float) $best > 0) {
                $wpm = (float) $best;
                $label = User::find($refId)?->username ?? 'Leaderboard';
            }
        }

        if ($wpm === null || $wpm <= 0) {
            return;
        }

        $this->dispatch('ghost-selected', type: $type, wpm: $wpm, label: $label);
        $this->dispatch('close-modal', 'ghost-picker');
    }

    public function clearOpponent(): void
    {
        $this->dispatch('ghost-cleared');
    }

    public function render()
    {
        return view('livewire.ghost-picker');
    }
}
