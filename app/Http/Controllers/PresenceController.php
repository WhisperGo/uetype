<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class PresenceController extends Controller
{
    /**
     * Heartbeat presence. Dipanggil klien secara berkala (setiap ~30 detik)
     * untuk menandai user masih aktif. Ringan sengaja: hanya meng-update
     * last_seen_at dan — pada transisi offline->online — menyiarkan ke teman.
     */
    public function heartbeat(): JsonResponse
    {
        Auth::user()->touchPresence();

        return response()->json(['ok' => true]);
    }
}
