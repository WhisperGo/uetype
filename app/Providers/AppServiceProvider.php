<?php

namespace App\Providers;

use App\Support\PageTitle;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Livewire\Volt\Volt;

/**
 * Application bootstrap: forces HTTPS behind a proxy, mounts Volt components,
 * registers the @localtime Blade directive, and injects the localized page title
 * into the app/guest layouts.
 */
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
        $this->guardAgainstNPlusOne();

        // Behind a reverse proxy a request can arrive as http; force https so
        // generated URLs don't become mixed content.
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

    /**
     * N+1 detector. Lazy loading a relation = an unplanned extra query; inside a loop
     * it turns into N+1.
     *
     * In LOCAL & TESTING this throws, so an N+1 is caught the moment it's introduced --
     * not after production slows down. This matters because ordinary functional tests
     * can't catch it: a page with 500 queries still "passes" as long as the output is
     * correct.
     *
     * In PRODUCTION it's off: an N+1 that slipped through is better slow than blowing up
     * in a user's face.
     *
     * If this throws, do NOT disable it -- eager-load the relation (`with()` /
     * `loadMissing()`); that's the fix.
     */
    private function guardAgainstNPlusOne(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
    }
}
