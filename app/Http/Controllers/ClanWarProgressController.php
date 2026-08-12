<?php

namespace App\Http\Controllers;

use App\Enums\ClanWarStatus;
use App\Models\ClanWarModeClaim;
use App\Services\ClanWarAttempt;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Where a running Clan War attempt reports what it has done so far.
 *
 * A plain endpoint rather than a Livewire method, for the same reason
 * MultiplayerPresenceController exists: a Livewire call is an XHR, and the browser cancels
 * in-flight XHRs when the page unloads. The ping that matters most is the one fired as the
 * player hits refresh, and that was precisely the one that never arrived -- so the server's
 * idea of "where they stopped" was always the previous routine ping, seconds behind. Reached
 * by `fetch(..., { keepalive: true })`, this one survives the unload.
 *
 * The second reason is cost. As a Livewire method every ping dragged textToType up and back
 * down, so it had to be throttled to one per five seconds; here the payload is five integers,
 * and the attempt can report every finished word.
 *
 * Nothing here is trusted. The claim is re-resolved against the caller's own clan, and every
 * number is bounded against the attempt's anchored clock inside ClanWarAttempt::recordProgress.
 */
class ClanWarProgressController extends Controller
{
    public function __invoke(Request $request, ClanWarAttempt $attempts): JsonResponse
    {
        $data = $request->validate([
            'claim' => ['required', 'integer'],
            // Resume position, in characters of the issued text.
            'chars' => ['required', 'integer', 'min:0'],
            // The running session's own ledger. Milliseconds since ITS first keystroke, and
            // the keystrokes counted since then -- never the attempt's totals, which the
            // server keeps and the client has no reason to know.
            'typedMs' => ['required', 'integer', 'min:0'],
            'totalKeystrokes' => ['required', 'integer', 'min:0'],
            'correctKeystrokes' => ['required', 'integer', 'min:0'],
        ]);

        $claim = ClanWarModeClaim::with('war')->find($data['claim']);

        // 403 rather than 404 for a claim that exists but is not the caller's: the beacon is
        // fire-and-forget and nothing reads the status, so the only audience is a developer
        // reading a log, and "you may not touch this" is the more useful thing to tell them.
        if (! $claim || ! $this->mayReport($claim)) {
            return response()->json(['ok' => false], 403);
        }

        // Survival never resumes -- a stamina curve cannot be continued -- so a ping for one is
        // accepted and dropped. Silently, because a well-behaved client does not send them and
        // a misbehaving one learns nothing from the difference.
        if ($claim->mode !== 'survival' && $claim->attemptStarted()) {
            $attempts->recordProgress(
                $claim,
                $data['chars'],
                $data['typedMs'],
                $data['totalKeystrokes'],
                $data['correctKeystrokes'],
            );
        }

        return response()->json(['ok' => true]);
    }

    /** The claim must be unsubmitted, in an ongoing war, and held by the caller's own clan. */
    private function mayReport(ClanWarModeClaim $claim): bool
    {
        if ($claim->isSubmitted()) {
            return false;
        }

        if (! $claim->war || $claim->war->status !== ClanWarStatus::Ongoing) {
            return false;
        }

        $clan = Auth::user()?->clan;

        return $clan !== null && $clan->id === $claim->clan_id;
    }
}
