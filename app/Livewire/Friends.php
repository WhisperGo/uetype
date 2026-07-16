<?php

namespace App\Livewire;

use App\Enums\FriendshipStatus;
use App\Enums\TypingMode;
use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\TypingResult;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The Friends page: lists current friends, incoming/outgoing requests, and a
 * username search to find and add new friends. Reacts to real-time friendship
 * and presence updates.
 */
class Friends extends Component
{
    // Tab aktif: 'friends' | 'requests' | 'find'
    public string $tab = 'friends';

    // Kotak pencarian username (tab Find Friends).
    public string $search = '';

    public function setTab(string $tab): void
    {
        if (in_array($tab, ['friends', 'requests', 'find'], true)) {
            $this->tab = $tab;
        }
    }

    /** Listener Echo untuk friends.{me}; body kosong karena action apa pun memicu re-render. */
    #[On('friendship-updated')]
    public function refreshFriends(): void
    {
        //
    }

    // ---- AKSI ----

    public function sendRequest(int $userId): void
    {
        $me = Auth::id();

        if ($userId === $me) {
            return;
        }

        // Cegah duplikat / kirim ulang jika relasi (arah mana pun) sudah ada.
        if (Auth::user()->friendshipWith($userId)) {
            return;
        }

        Friendship::create([
            'requester_id' => $me,
            'addressee_id' => $userId,
            'status' => FriendshipStatus::Pending,
        ]);

        $this->notify($userId, [
            'type' => 'request',
            'message' => Auth::user()->username.' sent you a friend request',
        ]);
    }

    public function acceptRequest(int $friendshipId): void
    {
        $friendship = $this->incomingPending($friendshipId);
        if (! $friendship) {
            return;
        }

        $friendship->update(['status' => FriendshipStatus::Accepted]);

        $this->notify($friendship->requester_id, [
            'type' => 'accepted',
            'message' => Auth::user()->username.' accepted your friend request',
        ]);
    }

    public function rejectRequest(int $friendshipId): void
    {
        $friendship = $this->incomingPending($friendshipId);
        if (! $friendship) {
            return;
        }

        $requesterId = $friendship->requester_id;
        $friendship->delete();

        $this->notify($requesterId);
    }

    public function cancelRequest(int $friendshipId): void
    {
        // Hanya pengirim yang boleh membatalkan permintaannya sendiri (masih pending).
        $friendship = Friendship::where('id', $friendshipId)
            ->where('requester_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->first();

        if (! $friendship) {
            return;
        }

        $addresseeId = $friendship->addressee_id;
        $friendship->delete();

        $this->notify($addresseeId);
    }

    public function removeFriend(int $friendshipId): void
    {
        // Salah satu pihak boleh menghapus pertemanan yang sudah accepted.
        $friendship = Friendship::where('id', $friendshipId)
            ->where('status', FriendshipStatus::Accepted)
            ->where(function ($q) {
                $q->where('requester_id', Auth::id())
                    ->orWhere('addressee_id', Auth::id());
            })
            ->first();

        if (! $friendship) {
            return;
        }

        $otherId = $friendship->requester_id === Auth::id()
            ? $friendship->addressee_id
            : $friendship->requester_id;

        $friendship->delete();

        $this->notify($otherId);
    }

    /** Permintaan masuk pending untuk user ini; hanya penerima yang boleh accept/reject. */
    private function incomingPending(int $friendshipId): ?Friendship
    {
        return Friendship::where('id', $friendshipId)
            ->where('addressee_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->first();
    }

    /** Siarkan perubahan ke user lain; $notification null berarti hanya segarkan daftar (tanpa toast). */
    private function notify(int $otherUserId, ?array $notification = null): void
    {
        SafeBroadcast::run(fn () => broadcast(new FriendshipUpdated($otherUserId, $notification)));
    }

    // ---- DATA (computed) ----

    public function getFriendsListProperty()
    {
        $me = Auth::id();

        $friendships = Friendship::with(['requester', 'addressee'])
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $me)->orWhere('addressee_id', $me))
            ->latest('updated_at')
            ->get();

        $friends = $friendships
            ->map(fn (Friendship $f) => [
                'friendship_id' => $f->id,
                'user' => $f->requester_id === $me ? $f->addressee : $f->requester,
            ])
            ->filter(fn ($row) => $row['user'] !== null)
            ->values();

        $ghostConfigsByUser = $this->ghostConfigsFor($friends->pluck('user.id')->all());

        return $friends->map(fn ($row) => [
            'friendship_id' => $row['friendship_id'],
            'user' => $row['user'],
            'online' => $row['user']->isOnline(),
            'ghost_configs' => $ghostConfigsByUser[$row['user']->id] ?? [],
        ]);
    }

    /**
     * Config per teman yang PUNYA rekor ghost (net_wpm > 0, mode time/words), untuk
     * membangun submenu Race Ghost yang hanya menampilkan pilihan yang benar-benar
     * ada lawannya. Satu query batch untuk seluruh daftar teman (bukan per baris).
     *
     * @param  array<int, int>  $userIds
     * @return array<int, array{time: list<string>, words: list<string>}>
     */
    private function ghostConfigsFor(array $userIds): array
    {
        if (empty($userIds)) {
            return [];
        }

        $timeOrder = ['15', '30', '60', '120'];
        $wordsOrder = ['10', '25', '50', '100'];

        $rows = TypingResult::whereIn('user_id', $userIds)
            ->whereIn('mode', ['time', 'words'])
            ->where('net_wpm', '>', 0)
            ->whereNotNull('mode_config')
            ->select('user_id', 'mode', 'mode_config')
            ->groupBy('user_id', 'mode', 'mode_config')
            ->get();

        $map = [];

        foreach ($rows as $row) {
            $mode = $row->mode instanceof TypingMode ? $row->mode->value : (string) $row->mode;
            $map[$row->user_id][$mode][] = (string) $row->mode_config;
        }

        return collect($map)->map(fn ($modes) => [
            'time' => array_values(array_intersect($timeOrder, $modes['time'] ?? [])),
            'words' => array_values(array_intersect($wordsOrder, $modes['words'] ?? [])),
        ])->all();
    }

    public function getIncomingRequestsProperty()
    {
        return Friendship::with('requester')
            ->where('addressee_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->latest()
            ->get()
            ->filter(fn ($f) => $f->requester !== null)
            ->values();
    }

    public function getSentRequestsProperty()
    {
        return Friendship::with('addressee')
            ->where('requester_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->latest()
            ->get()
            ->filter(fn ($f) => $f->addressee !== null)
            ->values();
    }

    public function getRequestsCountProperty(): int
    {
        return $this->incomingRequests->count() + $this->sentRequests->count();
    }

    /** Hasil pencarian username, lengkap status relasi tiap kandidat untuk tombol yang tepat. */
    public function getSearchResultsProperty()
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 1) {
            return collect();
        }

        $me = Auth::user();

        $candidates = User::where('id', '!=', $me->id)
            ->where('username', 'like', '%'.$term.'%')
            ->orderBy('username')
            ->limit(15)
            ->get();

        // Status relasi untuk SEMUA kandidat dalam satu query. Dulu friendshipWith()
        // dipanggil per baris -> 15 hasil pencarian = 15 query, tiap kali user mengetik
        // di kotak cari.
        $relations = Friendship::relationMapFor($me->id, $candidates->pluck('id')->all());

        return $candidates->map(fn (User $u) => [
            'user' => $u,
            'relation' => $relations[$u->id]['relation'] ?? 'none',
            'friendship_id' => $relations[$u->id]['friendship_id'] ?? null,
        ]);
    }

    public function render()
    {
        return view('livewire.friends')->layout('layouts.app');
    }
}
