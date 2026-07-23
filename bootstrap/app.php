<?php

use App\Http\Middleware\AnonymizeClientIp;
use App\Http\Middleware\SetLocale;
use Binafy\LaravelUserMonitoring\Middlewares\VisitMonitoringMiddleware;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->web(append: [
            SetLocale::class,
            // Masks the client IP before anything can log it. MUST stay ahead of the
            // monitoring middleware below, which reads request()->ip() as it records.
            AnonymizeClientIp::class,
            // Visit monitoring: records every page view (binafy/laravel-user-monitoring).
            VisitMonitoringMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions) {
        //
    })->create();
