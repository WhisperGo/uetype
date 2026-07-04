<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    private const SUPPORTED = ['id', 'en'];

    private const DEFAULT = 'id';

    public function handle(Request $request, Closure $next): Response
    {
        $locale = $request->session()->get('locale');

        App::setLocale(\in_array($locale, self::SUPPORTED, true) ? $locale : self::DEFAULT);

        return $next($request);
    }
}
