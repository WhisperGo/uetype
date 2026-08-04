<?php

namespace App\Enums;

/**
 * Lifecycle of a multiplayer room: waiting in the lobby, racing, or finished.
 *
 * These three values were magic strings in ~70 places — by far the most-repeated literals in
 * the app, and in its busiest code path — while every other domain here (clan membership,
 * friendship, clan war) already had an enum. A typo in one of them produces no error at all:
 * `$room->status === 'racng'` is simply false forever, and the branch it guards silently stops
 * running.
 *
 * Deliberately NOT the same thing as MultiplayerLobby::$step, which is the client-side view
 * state and stays a plain string. The two overlap in wording ('waiting', 'racing') but not in
 * meaning: `step` also has 'choose', and a FINISHED room shows the racing step so the result
 * panel can render over the arena. Conflating them is exactly the confusion this enum makes
 * visible — where a status is converted into a step, it now has to say so with ->value.
 */
enum RoomStatus: string
{
    case Waiting = 'waiting';
    case Racing = 'racing';
    case Finished = 'finished';
}
