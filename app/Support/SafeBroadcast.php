<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail-safe broadcast wrapper: a Reverb/WebSocket failure must not break the core
 * request (logout, send message, etc.). Replace `broadcast(new X)` with
 * `SafeBroadcast::run(fn () => broadcast(new X)->toOthers())`; the closure keeps
 * chaining like ->toOthers() writable at the call site.
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
