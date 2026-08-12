<?php

namespace App\Http\Controllers;

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Lightweight friend-related endpoints for the persistent nav (which is a static
 * Blade partial, not a Livewire component, so it can't re-query on its own).
 */
class FriendController extends Controller
{
    /**
     * Number of PENDING incoming friend requests for the current user -- powers the
     * nav badge. Same query as Friends::getIncomingRequestsProperty (incoming only:
     * addressee is me), kept as a count so it stays cheap. Called on page load's
     * server render and re-fetched by the nav whenever a friendship event arrives,
     * so the badge tracks both new requests and their cancel/accept/reject.
     */
    public function pendingCount(): JsonResponse
    {
        $count = Friendship::where('addressee_id', Auth::id())
            ->where('status', FriendshipStatus::Pending)
            ->count();

        return response()->json(['count' => $count]);
    }
}
