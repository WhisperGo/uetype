<?php

namespace App\Providers;

use App\Support\PageTitle;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Di balik reverse proxy/tunnel (Cloudflare, ngrok), request sampai ke Laravel
        // sebagai http polos meski browser mengaksesnya lewat https. Tanpa ini, URL yang
        // digenerate (route(), Livewire update endpoint, dll) memakai http dan diblokir
        // browser sebagai mixed content di halaman https.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        Volt::mount([
            resource_path('views/livewire'),
        ]);

        Blade::directive('localtime', function (string $expression) {
            return "<?php echo \\App\\Support\\AppTime::format({$expression}); ?>";
        });

        View::composer(['layouts.app', 'layouts.guest'], function ($view) {
            $view->with('pageTitle', PageTitle::forRoute(Route::currentRouteName()));
        });
    }
}


