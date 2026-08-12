<?php

namespace App\Listeners;

use App\Services\RoomMembershipService;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * A room membership must never outlive the login session that created it.
 *
 * The bug this closes: leave the site open, close the browser, come back the next day, log in
 * again, open /multiplayer -- and land straight back inside yesterday's room. TWO independent
 * mechanisms hid the staleness, and either one alone was enough to cause it:
 *
 *  1. sweepOfflineMembers() takes an $exceptUserId and never judges the caller. That exception
 *     is right for what it was written for (a heartbeat that has not landed yet, measured in
 *     seconds) but it also makes the caller's own DAY-OLD row permanently exempt.
 *  2. The presence heartbeat pings immediately on page load, so `last_seen_at` is already
 *     fresh by the time mount() could look at it. The evidence of the absence is destroyed
 *     before anything reads it.
 *
 * So the fix cannot be another last_seen_at rule -- that signal is gone by then. The session
 * boundary is the one thing that genuinely marks "the browser that held that room is no more".
 *
 * Hooked on the AUTH EVENTS rather than inside GoogleAuthController so every path is covered
 * at once (Google callback, /dev-login, anything added later) -- one definition, no drift.
 * markOffline() already runs on logout, but it only clears presence; it never released the
 * room, which is how an explicit logout still left a ghost host behind.
 *
 * depart() is the same door leave/kick/beacon use, so host handoff, delete-if-empty and the
 * roommate broadcasts all happen exactly as they would for a normal leave.
 *
 * NOTE this deliberately does NOT affect a page refresh: refreshing does not re-login, so the
 * mid-race restore in MultiplayerLobby::mount() is untouched.
 */
class DepartRoomsOnAuthChange
{
    public function __construct(private RoomMembershipService $memberships) {}

    public function handle(Login|Logout $event): void
    {
        // Logout can fire with no user (already-expired session); nothing to release then.
        if (! $event->user) {
            return;
        }

        $this->memberships->depart($event->user->getAuthIdentifier());
    }
}
