<?php

namespace App\Support;

use App\Events\ClanUpdated;
use App\Models\ClanWar;

/**
 * Silent real-time refresh for everyone taking part in a war.
 *
 * Reuses the per-user `clan.{userId}` channel with a NULL notification instead of adding a
 * war channel: toasts.js (listenClan) already re-raises `clan-updated-remote` on EVERY
 * `.clan.updated`, and only pops a toast when a message is present -- so a null payload is
 * exactly a "re-render yourself" signal, and every page already subscribed gets it for free.
 * A dedicated channel would need a new Echo subscription, a new JS bridge and a new
 * subscribe/unsubscribe lifecycle for a page that already has all three.
 *
 * The fan-out is bounded by the shape of a war, not by the size of the app: at most two
 * rosters, and at most 9 claims + 9 cancels + 9 submits per side over three days.
 */
class ClanWarBroadcast
{
    /**
     * Tell every participant to re-render.
     *
     * $exceptUserIds skips people who are already receiving a message-bearing ClanUpdated for
     * the SAME change (the leader who gets the "war accepted" toast, say): toasts.js refreshes
     * on that event too, so a second silent one would only buy them a duplicate round-trip.
     *
     * @param  array<int, int>  $exceptUserIds
     */
    public static function refresh(ClanWar $war, array $exceptUserIds = []): void
    {
        foreach ($war->participantUserIds() as $userId) {
            if (in_array($userId, $exceptUserIds, true)) {
                continue;
            }

            SafeBroadcast::run(fn () => broadcast(new ClanUpdated($userId, null)));
        }
    }
}
