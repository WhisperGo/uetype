<?php

namespace App\Http\Middleware;

use App\Support\IpAnonymizer;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Replaces the request's client IP with a masked one, so nothing downstream can persist a
 * full address.
 *
 * A complete IP is personal data under GDPR and UU PDP: it points at a household or device
 * and lives on in backups, which makes a leak of the monitoring tables a deanonymisation
 * tool. The activity logs only need to tell networks apart, not people.
 *
 * Masking here rather than at each write site is deliberate: the monitoring package records
 * IPs from three places inside vendor/ -- a middleware, a login/logout listener and the
 * Actionable trait -- and two of them bypass Eloquent with DB::table(), so there is no
 * single model hook that covers them. All three read request()->ip(), so overriding
 * REMOTE_ADDR before they run covers every path without touching vendor/.
 */
class AnonymizeClientIp
{
    public function handle(Request $request, Closure $next): Response
    {
        $masked = IpAnonymizer::mask($request->ip());

        if ($masked !== null) {
            // Rewrites what request()->ip() resolves to for the rest of this request.
            $request->server->set('REMOTE_ADDR', $masked);

            // Proxy headers are consulted first when the app trusts proxies, so they have
            // to be masked too or the original address would still win.
            foreach (['X-Forwarded-For', 'X-Real-IP', 'CF-Connecting-IP'] as $header) {
                if ($request->headers->has($header)) {
                    $request->headers->set($header, $masked);
                }
            }
        }

        return $next($request);
    }
}
