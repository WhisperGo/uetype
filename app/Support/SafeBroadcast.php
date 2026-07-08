<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pembungkus broadcast tahan-gagal: kegagalan Reverb/WebSocket tak boleh
 * menggagalkan request inti (logout, kirim pesan, dll). Ganti `broadcast(new X)`
 * dengan `SafeBroadcast::run(fn () => broadcast(new X)->toOthers())`; closure
 * dipakai agar chaining seperti ->toOthers() tetap bisa ditulis di titik panggil.
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
