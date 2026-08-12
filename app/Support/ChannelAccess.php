<?php

namespace App\Support;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * "Who may LISTEN on which broadcast channel" — the read-side counterpart to ChatAccess.
 *
 * These rules live in a class rather than inline in routes/channels.php for the same reason
 * ChatAccess exists: the closures in that file cannot be reached by a test. Under the test
 * configuration BROADCAST_CONNECTION is `null`, and NullBroadcaster::auth() is a no-op that
 * returns 200 for every request — so a test posting to /broadcasting/auth proves nothing at all,
 * including for a guest. Rules that cannot be tested are rules that quietly stop being true.
 *
 * Deliberately mirrors the WRITE side: mayReadClanChat() defers to ChatAccess::activeClan(), the
 * same call GuardsChatAccess makes before letting someone post. One definition of "in this clan",
 * so read access and write access cannot drift apart.
 */
class ChannelAccess
{
    /**
     * May this user listen on a channel keyed by THEIR OWN id (chat, friends, clan notices)?
     *
     * Guests are refused explicitly. Laravel already rejects an unauthenticated broadcasting-auth
     * request before the closure runs, but that is the framework's guarantee, not this rule's —
     * and this class is reached directly by tests, where nothing else would stop a null.
     */
    public static function ownsIdentityChannel(?Authenticatable $user, int|string $userId): bool
    {
        return $user !== null && (int) $user->getAuthIdentifier() === (int) $userId;
    }

    /** May this user listen on a clan's chat channel? Active membership only, as on the send path. */
    public static function mayReadClanChat(?Authenticatable $user, int|string $clanId): bool
    {
        if ($user === null) {
            return false;
        }

        return ChatAccess::activeClan((int) $user->getAuthIdentifier())?->id === (int) $clanId;
    }
}
