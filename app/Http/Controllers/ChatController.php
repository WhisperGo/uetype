<?php

namespace App\Http\Controllers;

use App\Events\ClanMessageSent;
use App\Events\DirectMessageSent;
use App\Models\Message;
use App\Models\User;
use App\Support\ChatAccess;
use App\Support\SafeBroadcast;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Lightweight, parallel message-send endpoint, deliberately outside Livewire so a
 * burst of messages isn't serialized by the component's request queue (each send is
 * a fire-and-forget fetch()).
 *
 * Authorization is not re-implemented here. It used to be -- this class carried its own copies
 * of "is an accepted friend", "my active clan" and both reply-target checks, alongside a
 * comment promising they matched App\Livewire\Chat "exactly". Nothing enforced that promise,
 * and the direction of failure is silent: tighten the rule in the component and this endpoint
 * keeps the old lock. Both now call App\Support\ChatAccess.
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

        if (! $friend || ! ChatAccess::isAcceptedFriend(Auth::id(), $friend->id)) {
            return response()->json(['ok' => false], 403);
        }

        $replyToId = ChatAccess::dmReplyTarget($replyToId, Auth::id(), $friend->id);

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
        $clan = ChatAccess::activeClan(Auth::id());

        if (! $clan) {
            return response()->json(['ok' => false], 403);
        }

        $replyToId = ChatAccess::clanReplyTarget($replyToId, $clan->id);

        $message = Message::create([
            'sender_id' => Auth::id(),
            'clan_id' => $clan->id,
            'body' => $body,
            'reply_to_id' => $replyToId,
        ]);

        SafeBroadcast::run(fn () => broadcast(new ClanMessageSent($message->load('sender'))));

        return response()->json(['ok' => true, 'id' => $message->id]);
    }
}
