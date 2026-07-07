<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pembungkus broadcast yang TAHAN-GAGAL. Real-time (Reverb/WebSocket) adalah
 * fitur "nice-to-have": kalau server Reverb sedang mati/timeout, itu TIDAK
 * boleh menggagalkan operasi inti yang memicunya (logout, kirim pesan, terima
 * teman, join/leave room, dll). Tanpa pembungkus ini, satu broadcast yang
 * gagal melempar BroadcastException dan menggagalkan seluruh HTTP request --
 * mis. user jadi tak bisa logout/join room hanya karena WebSocket down.
 *
 * Pola pemakaian (mengganti `broadcast(new X)` langsung):
 *   SafeBroadcast::run(fn () => broadcast(new PresenceUpdated($friendId)));
 *   SafeBroadcast::run(fn () => broadcast(new RoomUpdated($code))->toOthers());
 *
 * Closure dipakai (bukan sekadar terima event) supaya rantai seperti
 * ->toOthers() tetap bisa ditulis apa adanya di titik panggil.
 */
class SafeBroadcast
{
    public static function run(callable $broadcast): void
    {
        try {
            $broadcast();
        } catch (Throwable $e) {
            // Catat, jangan lempar: kegagalan real-time tak boleh merusak request.
            Log::warning('Broadcast gagal (diabaikan supaya request tetap lanjut): '.$e->getMessage());
        }
    }
}
