<?php

namespace App\Http\Controllers;

use App\Models\Room;
use App\Models\RoomMember;
use App\Services\RoomMembershipService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Lightweight endpoints that let a user leave a multiplayer room WITHOUT the Livewire
 * component (the /multiplayer nav is a full page load, so leaving happens on page unload
 * or on a nav click that the component never sees).
 *
 * Both hit the same RoomMembershipService the component uses -- one definition of "leave
 * the room" (delete membership, settle the room, reassign host, broadcast).
 */
class MultiplayerPresenceController extends Controller
{
    /**
     * Page-unload beacon (#2): a NOT-READY, non-host member who leaves /multiplayer is
     * removed so they don't hold a slot. Ready players and the host are deliberately kept
     * (their row survives so mount() can restore them when they return). Spectators (never
     * ready) are removed too -- they hold a spectator slot.
     *
     * Only while the room is 'waiting': pulling someone mid-race would corrupt placement.
     */
    public function leaveOnLeave(RoomMembershipService $memberships): JsonResponse
    {
        $member = RoomMember::where('user_id', Auth::id())->first();

        if ($member) {
            $room = Room::find($member->room_id);
            $isHost = $room && $room->host_id === Auth::id();

            if ($room && $room->status === 'waiting' && ! $isHost && ! $member->is_ready) {
                $memberships->depart(Auth::id());
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Ready-player confirmation (#3): the user confirmed they want to leave the page while
     * ready (or as host). Either way they're leaving, so we perform a full leave -- host
     * handoff included via the service -- rather than merely un-readying.
     *
     * Only while 'waiting' (same mid-race guard).
     */
    public function leaveOrUnready(RoomMembershipService $memberships): JsonResponse
    {
        $member = RoomMember::where('user_id', Auth::id())->first();

        if ($member) {
            $room = Room::find($member->room_id);

            if ($room && $room->status === 'waiting') {
                $memberships->depart(Auth::id());
            }
        }

        return response()->json(['ok' => true]);
    }
}
