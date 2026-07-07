<?php

namespace App\Livewire;

use App\Enums\FriendshipStatus;
use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

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

    /**
     * Dipanggil oleh listener Echo saat channel friends.{me} menerima event.
     * Livewire otomatis re-render sehingga daftar/permintaan selalu terkini
     * secara real-time (mis. saat ada yang mengirim/menerima permintaan).
     */
    #[On('friendship-updated')]
    public function refreshFriends(): void
    {
        // Body kosong: pemanggilan action apa pun memicu re-render, dan semua
        // data di render() adalah computed property yang di-query ulang.
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

        // Beri tahu penerima secara real-time (badge "Requests" bertambah) +
        // notifikasi "ada permintaan pertemanan dari <username>".
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

        // Pengirim asli langsung melihat statusnya jadi berteman + notifikasi
        // "permintaanmu diterima oleh <username>".
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
        // Hapus barisnya supaya bisa mengirim ulang di kemudian hari.
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

    /**
     * Ambil permintaan MASUK yang masih pending & ditujukan ke user ini.
     * Gerbang keamanan: hanya penerima yang boleh accept/reject.
     */
    private function incomingPending(int $friendshipId): ?Friendship
    {
        return Friendship::where('id', $friendshipId)
            ->where('addressee_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->first();
    }

    /**
     * Siarkan perubahan ke user LAIN (real-time). $notification opsional:
     * jika diisi, klien penerima memunculkan toast; jika null, hanya
     * menyegarkan daftar (mis. reject/cancel/remove — tak perlu toast).
     */
    private function notify(int $otherUserId, ?array $notification = null): void
    {
        broadcast(new FriendshipUpdated($otherUserId, $notification));
    }

    // ---- DATA (computed) ----

    public function getFriendsListProperty()
    {
        $me = Auth::id();

        return Friendship::with(['requester', 'addressee'])
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $me)->orWhere('addressee_id', $me))
            ->latest('updated_at')
            ->get()
            ->map(function (Friendship $f) use ($me) {
                $friend = $f->requester_id === $me ? $f->addressee : $f->requester;

                return [
                    'friendship_id' => $f->id,
                    'user' => $friend,
                    'online' => $friend?->isOnline() ?? false,
                ];
            })
            ->filter(fn ($row) => $row['user'] !== null)
            ->values();
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

    /**
     * Hasil pencarian username untuk tab Find Friends, lengkap dengan status
     * relasi tiap kandidat supaya tombol yang tepat ditampilkan
     * (Add / Request Sent / Incoming / Friends).
     */
    public function getSearchResultsProperty()
    {
        $term = trim($this->search);
        if (mb_strlen($term) < 1) {
            return collect();
        }

        $me = Auth::user();

        return User::where('id', '!=', $me->id)
            ->where('username', 'like', '%'.$term.'%')
            ->orderBy('username')
            ->limit(15)
            ->get()
            ->map(function (User $u) use ($me) {
                $friendship = $me->friendshipWith($u->id);

                $relation = 'none';
                $friendshipId = $friendship?->id;

                if ($friendship) {
                    if ($friendship->status === FriendshipStatus::Accepted) {
                        $relation = 'friends';
                    } elseif ($friendship->status === FriendshipStatus::Pending) {
                        $relation = $friendship->requester_id === $me->id ? 'sent' : 'incoming';
                    }
                }

                return ['user' => $u, 'relation' => $relation, 'friendship_id' => $friendshipId];
            });
    }

    public function render()
    {
        return view('livewire.friends')->layout('layouts.app');
    }
}
