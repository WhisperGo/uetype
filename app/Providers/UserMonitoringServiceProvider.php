<?php

namespace App\Providers;

use Binafy\LaravelUserMonitoring\Middlewares\VisitMonitoringMiddleware;
use Binafy\LaravelUserMonitoring\Providers\LaravelUserMonitoringEventServiceProvider;
use Binafy\LaravelUserMonitoring\Providers\LaravelUserMonitoringRouteServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Replacement for the default binafy/laravel-user-monitoring provider.
 *
 * The original package provider calls loadMigrationsFrom() into a vendor folder whose
 * migrations are dated 2023 -- so they run BEFORE the `users` table (2026) and fail
 * (FK to users). This provider does everything the original does EXCEPT
 * loadMigrationsFrom; the monitoring migrations are published to database/migrations
 * with a date after `users` so the ordering is correct.
 *
 * Package auto-discovery is disabled in composer.json (extra.laravel.dont-discover),
 * and this provider is registered manually in bootstrap/providers.php.
 */
class UserMonitoringServiceProvider extends ServiceProvider
{
    /** Wire up the monitoring package without its vendor migrations. */
    public function register(): void
    {
        $base = base_path('vendor/binafy/laravel-user-monitoring');

        $this->loadViewsFrom($base.'/resources/views/', 'LaravelUserMonitoring');
        // Deliberately NOT loadMigrationsFrom($base.'/database/migrations') --
        // the migrations are already published (and re-dated) to database/migrations.
        $this->mergeConfigFrom($base.'/config/user-monitoring.php', 'user-monitoring');

        $this->app['router']->aliasMiddleware('monitor-visit-middleware', VisitMonitoringMiddleware::class);

        $this->app->register(LaravelUserMonitoringEventServiceProvider::class);
        $this->app->register(LaravelUserMonitoringRouteServiceProvider::class);
    }
}
