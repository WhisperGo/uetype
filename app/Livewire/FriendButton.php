<?php

namespace App\Livewire;

use App\Enums\FriendshipStatus;
use App\Events\FriendshipUpdated;
use App\Models\Friendship;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Standalone friend-action button for the public profile page. Wraps the same
 * send/accept/cancel/remove logic as the Friends component, but for a single target user.
 */
class FriendButton extends Component
{
    // User target yang profilnya sedang dilihat (di-mount dari view profil).
    public User $target;

    public function mount(User $target): void
    {
        $this->target = $target;
    }

    /** Listener Echo friends.{me}; body kosong karena action apa pun memicu re-render. */
    #[On('friendship-updated')]
    public function refresh(): void
    {
        //
    }

    // ---- AKSI (cermin dari Friends.php, gerbang keamanan identik) ----

    public function sendRequest(): void
    {
        $me = Auth::id();

        if ($this->target->id === $me) {
            return;
        }

        // Cek "sudah berelasi (arah mana pun)" + insert dilakukan sebagai satu
        // operasi di dalam Friendship::requestBetween(), bukan dua langkah terpisah.
        if (! Friendship::requestBetween($me, $this->target->id)) {
            return;
        }

        $this->notify($this->target->id, [
            'type' => 'request',
            'message' => __('friends.notify.request', ['name' => Auth::user()->username]),
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
            'message' => __('friends.notify.accepted', ['name' => Auth::user()->username]),
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
        SafeBroadcast::run(fn () => broadcast(new FriendshipUpdated($otherUserId, $notification)));
    }

    /** Status relasi viewer-target: 'self' | 'none' | 'sent' | 'incoming' | 'friends'. */
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
