<?php

namespace App\Providers;

use App\Http\Middleware\EnsureUserIsAdmin;
use Binafy\LaravelUserMonitoring\Middlewares\VisitMonitoringMiddleware;
use Binafy\LaravelUserMonitoring\Providers\LaravelUserMonitoringEventServiceProvider;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Replacement for the default binafy/laravel-user-monitoring provider.
 *
 * It departs from the package in two places:
 *
 * 1. Migrations. The original calls loadMigrationsFrom() into a vendor folder whose
 *    migrations are dated 2023 -- so they run BEFORE the `users` table (2026) and fail
 *    (FK to users). The monitoring migrations are published to database/migrations with
 *    a date after `users` instead, so loadMigrationsFrom is deliberately not called.
 * 2. Routes. The package's LaravelUserMonitoringRouteServiceProvider hardcodes its
 *    middleware to web + visit-monitoring, leaving the dashboard open to the public.
 *    It is not registered; registerRoutes() below binds the same route file behind
 *    EnsureUserIsAdmin.
 *
 * Package auto-discovery is disabled in composer.json (extra.laravel.dont-discover),
 * and this provider is registered manually in bootstrap/providers.php.
 */
class UserMonitoringServiceProvider extends ServiceProvider
{
    /** Wire up the monitoring package without its vendor migrations or open routes. */
    public function register(): void
    {
        $base = base_path('vendor/binafy/laravel-user-monitoring');

        $this->loadViewsFrom($base.'/resources/views/', 'LaravelUserMonitoring');
        // Deliberately NOT loadMigrationsFrom($base.'/database/migrations') --
        // the migrations are already published (and re-dated) to database/migrations.
        $this->mergeConfigFrom($base.'/config/user-monitoring.php', 'user-monitoring');

        $this->app['router']->aliasMiddleware('monitor-visit-middleware', VisitMonitoringMiddleware::class);

        $this->app->register(LaravelUserMonitoringEventServiceProvider::class);

        $this->registerRoutes();
    }

    /**
     * Bind the monitoring dashboard routes to admins only.
     *
     * VisitMonitoringMiddleware is intentionally left off this group: all three dashboard
     * pages are already listed under visit_monitoring.except_pages, so it would never
     * record anything here. Ordinary page visits are still tracked because the middleware
     * is also appended to the global `web` stack in bootstrap/app.php.
     */
    protected function registerRoutes(): void
    {
        $path = base_path(
            config('user-monitoring.config.routes.file_path', 'routes/user-monitoring.php')
        );

        if (! file_exists($path)) {
            return;
        }

        Route::middleware(['web', EnsureUserIsAdmin::class])->group($path);
    }
}
