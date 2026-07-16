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

        // GROUP BY di query luar wajib: join mencocokkan `tr.net_wpm = pb.best_score`,
        // jadi user dengan DUA hasil ber-net_wpm identik akan muncul dua kali di
        // daftar lawan ghost (dan menggeser kandidat lain keluar dari 10 besar).
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

        // WPM/label diturunkan dari DB lewat resolver (satu sumber kebenaran, dipakai
        // juga saat memulihkan pilihan). Identitas tak sah -> null -> tak jadi apa-apa.
        $ghost = app(GhostResolver::class)->resolve($type, $refId, $this->mainMode, $this->subMode, Auth::id());

        if ($ghost === null) {
            return;
        }

        // Simpan IDENTITAS-nya (bukan angka wpm) supaya pilihan bertahan lintas tes /
        // reload dan bisa di-derive ulang. Dibaca kembali oleh TypingEngine::applyGhostRestore().
        session()->put('ghost_selection', ['type' => $type, 'ref_id' => $refId]);

        $this->dispatch('ghost-selected', type: $type, wpm: $ghost['wpm'], label: $ghost['label']);
        $this->dispatch('close-modal', 'ghost-picker');
    }

    public function clearOpponent(): void
    {
        // Clear eksplisit: buang identitas tersimpan supaya ghost TIDAK muncul lagi
        // di tes berikutnya (beda dari sekadar sembunyi saat pindah ke survival).
        session()->forget('ghost_selection');

        $this->dispatch('ghost-cleared');
    }

    public function render()
    {
        return view('livewire.ghost-picker');
    }
}
