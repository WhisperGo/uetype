<?php

namespace App\Livewire;

use App\Enums\FriendshipStatus;
use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Tombol aksi pertemanan yang berdiri sendiri, dipakai di halaman profil publik.
 * Membungkus logika kirim/terima/batal/hapus yang SAMA dengan komponen Friends
 * (gerbang keamanan & siaran real-time identik), tapi hanya untuk satu target
 * user — sehingga profil publik bisa mengelola pertemanan tanpa perlu meniru
 * seluruh UI tab daftar teman.
 */
class FriendButton extends Component
{
    // User target yang profilnya sedang dilihat (di-mount dari view profil).
    public User $target;

    public function mount(User $target): void
    {
        $this->target = $target;
    }

    /**
     * Segarkan tombol saat channel friends.{me} menerima event (mis. target
     * menerima/menolak permintaan kita) — statusnya selalu terkini real-time.
     */
    #[On('friendship-updated')]
    public function refresh(): void
    {
        // Body kosong: pemanggilan action apa pun memicu re-render, dan
        // status di render() selalu di-query ulang lewat computed property.
    }

    // ---- AKSI (cermin dari Friends.php, gerbang keamanan identik) ----

    public function sendRequest(): void
    {
        $me = Auth::id();

        if ($this->target->id === $me) {
            return;
        }

        // Cegah duplikat / kirim ulang jika relasi (arah mana pun) sudah ada.
        if (Auth::user()->friendshipWith($this->target->id)) {
            return;
        }

        Friendship::create([
            'requester_id' => $me,
            'addressee_id' => $this->target->id,
            'status' => FriendshipStatus::Pending,
        ]);

        $this->notify($this->target->id, [
            'type' => 'request',
            'message' => Auth::user()->username.' sent you a friend request',
        ]);
    }

    public function acceptRequest(): void
    {
        // Hanya penerima permintaan pending yang boleh menerima.
        $friendship = Friendship::where('requester_id', $this->target->id)
            ->where('addressee_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->first();

        if (! $friendship) {
            return;
        }

        $friendship->update(['status' => FriendshipStatus::Accepted]);

        $this->notify($this->target->id, [
            'type' => 'accepted',
            'message' => Auth::user()->username.' accepted your friend request',
        ]);
    }

    public function cancelRequest(): void
    {
        // Hanya pengirim yang boleh membatalkan permintaannya sendiri (pending).
        $friendship = Friendship::where('requester_id', Auth::id())
            ->where('addressee_id', $this->target->id)
            ->where('status', FriendshipStatus::Pending)
            ->first();

        if (! $friendship) {
            return;
        }

        $friendship->delete();
        $this->notify($this->target->id);
    }

    public function rejectRequest(): void
    {
        // Penerima menolak permintaan masuk dari target.
        $friendship = Friendship::where('requester_id', $this->target->id)
            ->where('addressee_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->first();

        if (! $friendship) {
            return;
        }

        $friendship->delete();
        $this->notify($this->target->id);
    }

    public function removeFriend(): void
    {
        // Salah satu pihak boleh menghapus pertemanan yang sudah accepted.
        $friendship = Friendship::where('status', FriendshipStatus::Accepted)
            ->where(function ($q) {
                $q->where(function ($inner) {
                    $inner->where('requester_id', Auth::id())
                        ->where('addressee_id', $this->target->id);
                })->orWhere(function ($inner) {
                    $inner->where('requester_id', $this->target->id)
                        ->where('addressee_id', Auth::id());
                });
            })
            ->first();

        if (! $friendship) {
            return;
        }

        $friendship->delete();
        $this->notify($this->target->id);
    }

    private function notify(int $otherUserId, ?array $notification = null): void
    {
        broadcast(new FriendshipUpdated($otherUserId, $notification));
    }

    /**
     * Status relasi antara viewer dan target: 'self' | 'none' | 'sent'
     * | 'incoming' | 'friends'. Menentukan tombol mana yang dirender.
     */
    public function getRelationProperty(): string
    {
        $me = Auth::user();

        if (! $me || $me->id === $this->target->id) {
            return 'self';
        }

        $friendship = $me->friendshipWith($this->target->id);

        if (! $friendship) {
            return 'none';
        }

        if ($friendship->status === FriendshipStatus::Accepted) {
            return 'friends';
        }

        if ($friendship->status === FriendshipStatus::Pending) {
            return $friendship->requester_id === $me->id ? 'sent' : 'incoming';
        }

        return 'none';
    }

    public function render()
    {
        return view('livewire.friend-button');
    }
}
