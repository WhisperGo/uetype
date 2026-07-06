<?php

namespace App\Providers;

use App\Support\PageTitle;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
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
