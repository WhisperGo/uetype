<?php

namespace App\Providers;

use App\Listeners\DepartRoomsOnAuthChange;
use App\Models\User;
use App\Support\PageTitle;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
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

        // Single definition of "may open the monitoring dashboard". EnsureUserIsAdmin is
        // what actually gates the routes; this Gate expresses the same rule so views and
        // any future callers can ask authorization rather than re-reading is_admin.
        Gate::define('access-monitoring', fn (User $user) => (bool) $user->is_admin);

        // Behind a reverse proxy a request can arrive as http; force https so
        // generated URLs don't become mixed content.
        if (str_starts_with((string) config('app.url'), 'https://')) {
            URL::forceScheme('https');
        }

        // A multiplayer room membership must not survive the login session that made it --
        // see DepartRoomsOnAuthChange for why last_seen_at cannot answer this. Registered
        // EXPLICITLY rather than relying on event auto-discovery: bootstrap/app.php does not
        // call withEvents(), so a listener dropped into app/Listeners would simply never run
        // and the failure would be silent.
        Event::listen([Login::class, Logout::class], DepartRoomsOnAuthChange::class);

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
