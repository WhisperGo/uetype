<?php

namespace App\Http\Middleware;

use App\Support\Locale;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->user()?->preferences['locale']
            ?? $request->session()->get('locale');

        App::setLocale(Locale::resolve($locale));

        return $next($request);
    }
}
