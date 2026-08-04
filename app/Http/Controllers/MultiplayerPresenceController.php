<?php

namespace App\Http\Controllers;

use App\Enums\RoomStatus;
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
     * Only while the room is 'waiting'. A page-unload during 'racing' is treated as a
     * RELOAD, not a departure: the row is kept untouched so mount() restores the player
     * into the arena at their saved progress. Pulling them out here would also corrupt
     * placement. (An intentional leave is a separate, explicit action.)
     */
    public function leaveOnLeave(RoomMembershipService $memberships): JsonResponse
    {
        $member = RoomMember::where('user_id', Auth::id())->first();

        if ($member) {
            $room = Room::find($member->room_id);
            $isHost = $room && $room->host_id === Auth::id();

            if ($room && $room->status === RoomStatus::Waiting && ! $isHost && ! $member->is_ready) {
                $memberships->depart(Auth::id());
            }
        }

        return response()->json(['ok' => true]);
    }

    /**
     * Confirmed leave (#3): the user pressed "Leave" on the nav-away overlay -- an EXPLICIT
     * choice to leave the room, so we perform a full leave (host handoff included) whatever
     * the room status.
     *
     * This is the key difference from the leave-beacon (#2): that fires on any page-unload
     * (refresh / tab close), which mid-race is a reload we must NOT act on -- the row has to
     * survive so mount() restores the racer at their progress. A confirmed nav is the
     * opposite: the player deliberately chose to leave, so a mid-race departure removes them
     * outright. The remaining racers simply finalize among themselves (a vanished member
     * isn't in the standings); their placement isn't corrupted because places are only
     * counted for members still present at finalization.
     */
    public function leaveOrUnready(RoomMembershipService $memberships): JsonResponse
    {
        $member = RoomMember::where('user_id', Auth::id())->first();

        if ($member) {
            $room = Room::find($member->room_id);

            if ($room) {
                $memberships->depart(Auth::id());
            }
        }

        return response()->json(['ok' => true]);
    }
}
