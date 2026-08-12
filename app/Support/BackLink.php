<?php

namespace App\Support;

use Illuminate\Http\Request;

/**
 * Where a "back" control should point on a page reachable from many places.
 *
 * Read from the Referer, not a `?from=` param: no caller has to pass anything, shared links
 * still behave, and the links that lead here stay clean -- the clan pages' chat links are
 * asserted verbatim by ChatTest, so appending a param would break them.
 *
 * The header is client-controlled, hence three guards: the same host, or it is an open
 * redirect; not one of the caller's excluded origins; and only ever a PATH is returned,
 * never a host.
 *
 * Deliberately generic rather than offering forProfile()/forChat() named constructors: the
 * fallback and the exclusions are POLICY belonging to each caller, and moving that knowledge
 * in here would invert the dependency. Each caller keeps its policy beside the code that
 * reads it.
 */
class BackLink
{
    /**
     * @param  string  $fallback  returned verbatim when the Referer is unusable
     * @param  array<int, string>  $except  path prefixes the caller must never be sent back to
     */
    public static function from(Request $request, string $fallback, array $except = []): string
    {
        $referer = $request->headers->get('referer');

        if (! $referer) {
            return $fallback;
        }

        $parts = parse_url($referer);

        // No host at all means it could not be checked -- treat it as foreign. This also
        // catches the protocol-relative `//evil.example.com/x`, which reads as a path to the
        // naked eye but as a host to parse_url().
        if (! isset($parts['host']) || $parts['host'] !== $request->getHost()) {
            return $fallback;
        }

        $path = $parts['path'] ?? '/';

        foreach ($except as $prefix) {
            if (str_starts_with($path, $prefix)) {
                return $fallback;
            }
        }

        return $path.(isset($parts['query']) ? '?'.$parts['query'] : '');
    }
}
