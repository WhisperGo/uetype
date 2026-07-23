<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Symfony\Component\HttpFoundation\Response;

/**
 * Restricts a route to users with `is_admin`, hiding it from everyone else.
 *
 * Guests and signed-in non-admins get the SAME 404: a 403 (or a redirect to login)
 * would confirm the page exists, which is exactly what an admin panel should not leak.
 * That is also why 'auth' is not used alongside this -- it redirects guests to login
 * and gives the URL away.
 */
class EnsureUserIsAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        // Defers to the `access-monitoring` Gate (AppServiceProvider) so the rule lives in
        // one place; Gate::allows() is false for guests, giving them the same 404.
        abort_unless(Gate::allows('access-monitoring'), 404);

        return $next($request);
    }
}
