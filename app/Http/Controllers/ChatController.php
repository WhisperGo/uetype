<?php

namespace App\Http\Controllers;

use App\Enums\ClanMemberStatus;
use App\Enums\FriendshipStatus;
use App\Events\ClanMessageSent;
use App\Events\DirectMessageSent;
use App\Models\ClanMember;
use App\Models\Message;
use App\Models\User;
use App\Support\SafeBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lightweight, parallel message-send endpoint, deliberately outside Livewire so a
 * burst of messages isn't serialized by the component's request queue (each send is
 * a fire-and-forget fetch()). Validation & authorization match App\Livewire\Chat exactly.
 */
class ChatController extends Controller
{
    /** Validate the payload and dispatch it to the DM or clan send path. */
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:dm,clan',
            'body' => 'required|string|max:2000',
            'with' => 'nullable|string',        // friend's username (dm mode)
            'reply_to_id' => 'nullable|integer', // the message being replied to
        ]);

        $body = trim($data['body']);

        if ($body === '') {
            return response()->json(['ok' => false], 422);
        }

        return $data['mode'] === 'clan'
            ? $this->sendClan($body, $data['reply_to_id'] ?? null)
            : $this->sendDm($data['with'] ?? null, $body, $data['reply_to_id'] ?? null);
    }

    /** Send a DM to an accepted friend; broadcasts to the recipient. */
    private function sendDm(?string $username, string $body, ?int $replyToId): JsonResponse
    {
        $friend = $username ? User::where('username', $username)->first() : null;

        if (! $friend || ! $this->isAcceptedFriend($friend->id)) {
            return response()->json(['ok' => false], 403);
        }

        $replyToId = $this->resolveDmReply($replyToId, $friend->id);

        $message = Message::create([
            'sender_id' => Auth::id(),
            'recipient_id' => $friend->id,
            'body' => $body,
            'reply_to_id' => $replyToId,
        ]);

        SafeBroadcast::run(fn () => broadcast(new DirectMessageSent($message->load('sender'))));

        return response()->json(['ok' => true, 'id' => $message->id]);
    }

    /** Send a message to the sender's active clan; broadcasts to the clan. */
    private function sendClan(string $body, ?int $replyToId): JsonResponse
    {
        $clan = $this->activeClan();

        if (! $clan) {
            return response()->json(['ok' => false], 403);
        }

        $replyToId = $this->resolveClanReply($replyToId, $clan->id);

        $message = Message::create([
            'sender_id' => Auth::id(),
            'clan_id' => $clan->id,
            'body' => $body,
            'reply_to_id' => $replyToId,
        ]);

        SafeBroadcast::run(fn () => broadcast(new ClanMessageSent($message->load('sender'))));

        return response()->json(['ok' => true, 'id' => $message->id]);
    }

    // ---- authorization (mirror App\Livewire\Chat) ----

    /** Whether the given user is an accepted friend of the current user. */
    private function isAcceptedFriend(int $otherId): bool
    {
        return Auth::user()->friendshipWith($otherId)?->status === FriendshipStatus::Accepted;
    }

    /** The current user's active clan, or null if they aren't in one. */
    private function activeClan()
    {
        return ClanMember::with('clan')
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Active)
            ->first()?->clan;
    }

    /** Accept the reply target only if it belongs to this DM thread; else null. */
    private function resolveDmReply(?int $replyToId, int $friendId): ?int
    {
        if (! $replyToId) {
            return null;
        }

        $target = Message::find($replyToId);
        $me = Auth::id();

        return $target && ! $target->isClanMessage()
            && in_array($friendId, [$target->sender_id, $target->recipient_id], true)
            && in_array($me, [$target->sender_id, $target->recipient_id], true)
            ? $target->id
            : null;
    }

    /** Accept the reply target only if it belongs to this clan; else null. */
    private function resolveClanReply(?int $replyToId, int $clanId): ?int
    {
        if (! $replyToId) {
            return null;
        }

        $target = Message::find($replyToId);

        return $target && $target->clan_id === $clanId ? $target->id : null;
    }
}
