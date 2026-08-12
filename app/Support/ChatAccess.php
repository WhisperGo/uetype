<?php

namespace App\Support;

use App\Enums\ClanMemberStatus;
use App\Enums\FriendshipStatus;
use App\Models\Clan;
use App\Models\ClanMember;
use App\Models\Message;
use App\Models\User;

/**
 * The "who may talk to whom, and reply to what" rules for chat — in ONE place.
 *
 * These rules used to live twice: once in the Livewire traits (GuardsChatAccess /
 * ManagesChatConversation) and once, re-implemented, in ChatController. The controller cannot
 * use a Livewire trait, and it exists for a real reason (a send has to run outside Livewire's
 * request queue so a burst of messages isn't serialized), so the duplication was not laziness.
 * It was still duplication, and of the worst kind: the controller's own doc comment promised
 * "validation & authorization match App\Livewire\Chat exactly", a promise nothing enforced.
 *
 * The risk is one-directional and quiet. Tighten the DM rule in one place — block a user,
 * mute someone, require a friendship to be non-blocked — and the other path keeps accepting
 * what the first now refuses. Nothing errors; there is simply a second door with the old lock.
 *
 * Plain static methods rather than a trait or a service, because both callers need the same
 * answer and neither needs state: a trait would exclude the controller again, which is how
 * this started.
 */
class ChatAccess
{
    /** Accepted friendship in either direction. Re-checked server-side on every action. */
    public static function isAcceptedFriend(int $userId, int $otherId): bool
    {
        $user = $userId === auth()->id() ? auth()->user() : User::find($userId);

        return $user?->friendshipWith($otherId)?->status === FriendshipStatus::Accepted;
    }

    /** The user's active clan, or null if they aren't in one. */
    public static function activeClan(int $userId): ?Clan
    {
        return ClanMember::with('clan')
            ->where('user_id', $userId)
            ->where('status', ClanMemberStatus::Active)
            ->first()?->clan;
    }

    /**
     * May this user see the message: a DM they are part of, or their own clan's.
     *
     * $clanId is the viewer's active clan (null if none) rather than being looked up here, so
     * a caller rendering a whole conversation resolves it once instead of per message.
     */
    public static function canSeeMessage(Message $message, int $userId, ?int $clanId): bool
    {
        if ($message->isClanMessage()) {
            return $clanId !== null && $message->clan_id === $clanId;
        }

        return $message->sender_id === $userId || $message->recipient_id === $userId;
    }

    /**
     * A reply target inside THIS DM thread, or null.
     *
     * Both participants are checked, not just the sender: without that, replying to an
     * arbitrary message id would quote a conversation the replier was never part of into one
     * they are — the reply preview renders the quoted body.
     */
    public static function dmReplyTarget(?int $replyToId, int $userId, int $friendId): ?int
    {
        $target = self::replyCandidate($replyToId);

        if (! $target || $target->isClanMessage()) {
            return null;
        }

        $participants = [$target->sender_id, $target->recipient_id];

        return in_array($userId, $participants, true) && in_array($friendId, $participants, true)
            ? $target->id
            : null;
    }

    /** A reply target inside THIS clan's chat, or null. */
    public static function clanReplyTarget(?int $replyToId, int $clanId): ?int
    {
        $target = self::replyCandidate($replyToId);

        return $target && $target->clan_id === $clanId ? $target->id : null;
    }

    /**
     * The message a reply points at, if it is one that may be quoted at all.
     *
     * "Deleted for everyone" is refused here, which is what startReply() has always done at
     * the UI entry point — but only there, so a hand-made request could still quote a body its
     * sender had retracted. Enforcing it alongside the ownership checks puts the rule on the
     * path every reply actually takes.
     */
    private static function replyCandidate(?int $replyToId): ?Message
    {
        if (! $replyToId) {
            return null;
        }

        $target = Message::find($replyToId);

        return $target && ! $target->isDeletedForEveryone() ? $target : null;
    }
}
