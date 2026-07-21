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

    /** Echo listener for friends.{me}; empty body because any action triggers a re-render. */
    #[On('friendship-updated')]
    public function refreshFriends(): void
    {
        //
    }

    // ---- ACTIONS ----

    public function sendRequest(int $userId): void
    {
        $me = Auth::id();

        if ($userId === $me) {
            return;
        }

        // The "already related (either direction)" check + insert happen as one operation
        // inside Friendship::requestBetween(), not two separate steps.
        if (! Friendship::requestBetween($me, $userId)) {
            return;
        }

        $this->notify($userId, [
            'type' => 'request',
            'message' => __('friends.notify.request', ['name' => Auth::user()->username]),
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
            'message' => __('friends.notify.accepted', ['name' => Auth::user()->username]),
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
        // Only the sender may cancel their own (still pending) request.
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
        // Either party may remove an accepted friendship.
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

    /** A pending incoming request for this user; only the recipient may accept/reject. */
    private function incomingPending(int $friendshipId): ?Friendship
    {
        return Friendship::where('id', $friendshipId)
            ->where('addressee_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->first();
    }

    /** Broadcast the change to the other user; $notification null means just refresh the list (no toast). */
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
     * Per-friend config for friends that HAVE a ghost record (net_wpm > 0, time/words
     * mode), to build the Race Ghost submenu that only lists options with an actual
     * opponent. One batched query for the whole friend list (not per row).
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

    /** Username search results, with each candidate's relation status for the right button. */
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

        // Relation status for ALL candidates in one query. Previously friendshipWith() was
        // called per row -> 15 search results = 15 queries, every time the user typed in the
        // search box.
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
