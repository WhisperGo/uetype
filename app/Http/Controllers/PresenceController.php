<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

/**
 * Handles online-presence heartbeats used to show friends' online/offline status.
 */
class PresenceController extends Controller
{
    /**
     * Presence heartbeat, called periodically by the client to mark the user as
     * still active. Updates last_seen_at, and on an offline->online transition
     * broadcasts the change to the user's friends.
     */
    public function heartbeat(): JsonResponse
    {
        Auth::user()->touchPresence();

        return response()->json(['ok' => true]);
    }
}
