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
    // The target user whose profile is being viewed (mounted from the profile view).
    public User $target;

    public function mount(User $target): void
    {
        $this->target = $target;
    }

    /** Echo listener for friends.{me}; empty body because any action triggers a re-render. */
    #[On('friendship-updated')]
    public function refresh(): void
    {
        //
    }

    // ---- ACTIONS (mirror of Friends.php, identical security gates) ----

    public function sendRequest(): void
    {
        $me = Auth::id();

        if ($this->target->id === $me) {
            return;
        }

        // The "already related (either direction)" check + insert happen as one operation
        // inside Friendship::requestBetween(), not two separate steps.
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
        // Only the recipient of a pending request may accept it.
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
        // Only the sender may cancel their own (pending) request.
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
        // The recipient rejects an incoming request from the target.
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
        // Either party may remove an accepted friendship.
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
