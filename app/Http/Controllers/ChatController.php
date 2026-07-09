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
 * Endpoint kirim pesan yang ringan & paralel, sengaja di luar Livewire supaya spam
 * pesan tak ter-serialize oleh antrean request komponen (tiap kiriman = fetch()
 * fire-and-forget). Validasi & otorisasi sama persis dengan App\Livewire\Chat.
 */
class ChatController extends Controller
{
    public function send(Request $request): JsonResponse
    {
        $data = $request->validate([
            'mode' => 'required|in:dm,clan',
            'body' => 'required|string|max:2000',
            'with' => 'nullable|string',        // username teman (mode dm)
            'reply_to_id' => 'nullable|integer', // pesan yang dibalas
        ]);

        $body = trim($data['body']);

        if ($body === '') {
            return response()->json(['ok' => false], 422);
        }

        return $data['mode'] === 'clan'
            ? $this->sendClan($body, $data['reply_to_id'] ?? null)
            : $this->sendDm($data['with'] ?? null, $body, $data['reply_to_id'] ?? null);
    }

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

    // ---- otorisasi (mirror App\Livewire\Chat) ----

    private function isAcceptedFriend(int $otherId): bool
    {
        return Auth::user()->friendshipWith($otherId)?->status === FriendshipStatus::Accepted;
    }

    private function activeClan()
    {
        return ClanMember::with('clan')
            ->where('user_id', Auth::id())
            ->where('status', ClanMemberStatus::Active)
            ->first()?->clan;
    }

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

    private function resolveClanReply(?int $replyToId, int $clanId): ?int
    {
        if (! $replyToId) {
            return null;
        }

        $target = Message::find($replyToId);

        return $target && $target->clan_id === $clanId ? $target->id : null;
    }
}
