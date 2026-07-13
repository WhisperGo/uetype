<?php

namespace App\Providers;

use Binafy\LaravelUserMonitoring\Middlewares\VisitMonitoringMiddleware;
use Binafy\LaravelUserMonitoring\Providers\LaravelUserMonitoringEventServiceProvider;
use Binafy\LaravelUserMonitoring\Providers\LaravelUserMonitoringRouteServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Pengganti provider bawaan binafy/laravel-user-monitoring.
 *
 * Provider paket aslinya memanggil loadMigrationsFrom() ke folder vendor yang
 * migrasinya bertanggal 2023 — jadi jalan SEBELUM tabel `users` (2026) dan
 * gagal (FK ke users). Provider ini melakukan semua yang dilakukan provider
 * asli KECUALI loadMigrationsFrom; migrasi monitoring dipublish ke
 * database/migrations dengan tanggal setelah `users` supaya urutannya benar.
 *
 * Auto-discovery paket dimatikan di composer.json (extra.laravel.dont-discover),
 * dan provider ini didaftarkan manual di bootstrap/providers.php.
 */
class UserMonitoringServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $base = base_path('vendor/binafy/laravel-user-monitoring');

        $this->loadViewsFrom($base.'/resources/views/', 'LaravelUserMonitoring');
        // Sengaja TIDAK loadMigrationsFrom($base.'/database/migrations') —
        // migrasinya sudah dipublish (dan di-retanggal) ke database/migrations.
        $this->mergeConfigFrom($base.'/config/user-monitoring.php', 'user-monitoring');

        $this->app['router']->aliasMiddleware('monitor-visit-middleware', VisitMonitoringMiddleware::class);

        $this->app->register(LaravelUserMonitoringEventServiceProvider::class);
        $this->app->register(LaravelUserMonitoringRouteServiceProvider::class);
    }
}
