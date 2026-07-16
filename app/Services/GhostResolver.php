<?php

namespace App\Services;

use App\Enums\FriendshipStatus;
use App\Models\Friendship;
use App\Models\TypingResult;
use App\Models\User;

/**
 * Menurunkan lawan ghost dari sebuah IDENTITAS (type + refId), bukan dari angka
 * WPM yang dikirim client. SATU sumber kebenaran untuk derive ghost.
 *
 * Trust boundary: client hanya menyodorkan identifier; WPM selalu diambil ulang
 * dari DB di sini -- dipakai saat memilih (GhostPicker) MAUPUN saat memulihkan
 * pilihan yang tersimpan (TypingEngine), jadi rematch tak pernah membekukan angka
 * lama dan tak bisa dipalsukan lewat state client.
 *
 * Sebelumnya logika ini terduplikasi di GhostPicker::selectOpponent() dan
 * TypingEngine::resolveGhostDeepLink().
 */
class GhostResolver
{
    /**
     * @param  string  $type  'own' | 'friend' | 'leaderboard'
     * @param  int|null  $refId  friendship_id (friend) | user_id (leaderboard) | null (own)
     * @param  string  $mainMode  'time' | 'words' (ghost tak berlaku di survival)
     * @param  string  $subMode  konfigurasi mode aktif
     * @param  int|null  $viewerId  user yang sedang bermain
     * @return array{type: string, wpm: float, label: string}|null null kalau tak sah / tak ada rekor
     */
    public function resolve(string $type, ?int $refId, string $mainMode, string $subMode, ?int $viewerId): ?array
    {
        $resolved = match ($type) {
            'own' => $this->resolveOwn($viewerId),
            'friend' => $this->resolveFriend($refId, $viewerId),
            'leaderboard' => $this->resolveLeaderboard($refId, $mainMode, $subMode),
            default => null,
        };

        // Gerbang tunggal: WPM harus positif, apa pun jenis lawannya.
        if ($resolved === null || $resolved['wpm'] <= 0) {
            return null;
        }

        return ['type' => $type] + $resolved;
    }

    /** Rekor terbaik pemain sendiri (mode-independen: highest_wpm). */
    private function resolveOwn(?int $viewerId): ?array
    {
        if ($viewerId === null) {
            return null;
        }

        $wpm = (float) (User::find($viewerId)?->highest_wpm ?? 0);

        return ['wpm' => $wpm, 'label' => 'Your Best'];
    }

    /**
     * Rekor terbaik seorang teman (highest_wpm). refId = friendship_id, WAJIB
     * pertemanan accepted yang melibatkan viewer -- cegah menebak ID orang lain.
     */
    private function resolveFriend(?int $refId, ?int $viewerId): ?array
    {
        if ($refId === null || $viewerId === null) {
            return null;
        }

        $friendship = Friendship::where('id', $refId)
            ->where('status', FriendshipStatus::Accepted)
            ->where(fn ($q) => $q->where('requester_id', $viewerId)->orWhere('addressee_id', $viewerId))
            ->with(['requester', 'addressee'])
            ->first();

        if (! $friendship) {
            return null;
        }

        $friend = $friendship->requester_id === $viewerId
            ? $friendship->addressee
            : $friendship->requester;

        if (! $friend) {
            return null;
        }

        return ['wpm' => (float) $friend->highest_wpm, 'label' => $friend->username];
    }

    /**
     * Rekor terbaik seorang user di mode+config AKTIF (MAX net_wpm). refId = user_id.
     * Kalau user tak punya rekor di config itu -> null (fail-safe; ghost tersembunyi).
     */
    private function resolveLeaderboard(?int $refId, string $mainMode, string $subMode): ?array
    {
        if ($refId === null) {
            return null;
        }

        $best = TypingResult::where('user_id', $refId)
            ->where('mode', $mainMode)
            ->where('mode_config', $subMode)
            ->max('net_wpm');

        if ($best === null) {
            return null;
        }

        $label = User::find($refId)?->username ?? 'Leaderboard';

        return ['wpm' => (float) $best, 'label' => $label];
    }
}
