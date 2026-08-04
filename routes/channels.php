<?php

use App\Support\ChannelAccess;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Broadcast channel authorization
|--------------------------------------------------------------------------
|
| Every channel keyed by a SEQUENTIAL id is authorized here. That is the whole rule, and it
| draws the line this file exists to draw:
|
|   - room.{code} / race.{code} stay PUBLIC. Their secret is a 6-character random code that
|     cannot be enumerated, and spectators plus invite deep-links depend on being able to
|     subscribe without a membership row existing yet.
|   - chat.{userId}, clan-chat.{clanId}, friends.{userId}, clan.{userId} are PRIVATE. Ids run
|     from 1, so "unguessable" was never true for them -- `Echo.channel('chat.5')` used to
|     stream user #5's incoming DMs to anyone who asked, and the Reverb app key needed to ask
|     is public by necessity (it ships in the JS bundle).
|
| This file previously held only Laravel's scaffolded 'App.Models.User.{id}' channel, which no
| event in app/Events ever broadcast on -- so nothing was authorized and nothing was protected.
|
| A guest never reaches these closures: Laravel rejects an unauthenticated broadcasting auth
| request before resolving the channel. The rules themselves live in App\Support\ChannelAccess
| because closures in this file cannot be tested -- under BROADCAST_CONNECTION=null, which is
| what the suite runs, NullBroadcaster::auth() is a no-op that authorizes everything.
*/

/** A user's own DM stream. The body of every incoming message travels here. */
Broadcast::channel('chat.{userId}', fn ($user, $userId) => ChannelAccess::ownsIdentityChannel($user, $userId));

/** A clan's shared chat: active members only, the same rule the send path applies. */
Broadcast::channel('clan-chat.{clanId}', fn ($user, $clanId) => ChannelAccess::mayReadClanChat($user, $clanId));

/**
 * A user's friend/presence feed -- and the channel RoomInvitationSent delivers on.
 *
 * That last part is why this one is private rather than merely tidy: the invite payload carries
 * a room code, the same secret room.{code} relies on. Leaving this public meant the public
 * channel handed out the private one's key.
 */
Broadcast::channel('friends.{userId}', fn ($user, $userId) => ChannelAccess::ownsIdentityChannel($user, $userId));

/** A user's clan notifications (membership changes, war lifecycle). */
Broadcast::channel('clan.{userId}', fn ($user, $userId) => ChannelAccess::ownsIdentityChannel($user, $userId));
