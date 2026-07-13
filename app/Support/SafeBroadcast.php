<?php

namespace App\Support;

use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Fail-safe broadcast wrapper: a Reverb/WebSocket failure must not break the core
 * request. Use `SafeBroadcast::run(fn () => broadcast(new X)->toOthers())`; the
 * closure keeps chaining like ->toOthers() writable at the call site.
 */
class SafeBroadcast
{
    /** Run a broadcast closure, swallowing (and logging) any failure. */
    public static function run(callable $broadcast): void
    {
        try {
            $broadcast();
        } catch (Throwable $e) {
            // Log, don't throw: a real-time failure must not break the request.
            Log::warning('Broadcast failed (ignored so the request can continue): '.$e->getMessage());
        }
    }
}
