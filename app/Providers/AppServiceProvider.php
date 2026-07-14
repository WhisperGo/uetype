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

        // Di belakang reverse proxy request bisa masuk sebagai http; paksa https
        // agar URL yang digenerate tak jadi mixed content.
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
     * Detektor N+1. Lazy loading sebuah relasi = query tambahan yang tak direncanakan;
     * di dalam loop, ia berubah jadi N+1.
     *
     * Di LOKAL & TESTING ini melempar exception, jadi N+1 ketahuan saat dibuat --
     * bukan setelah produksi melambat. Ini penting karena test fungsional biasa TIDAK
     * bisa menangkapnya: halaman dengan 500 query tetap "lulus" selama outputnya benar.
     *
     * Di PRODUKSI dimatikan: sebuah N+1 yang lolos lebih baik pelan daripada
     * meledak di muka user.
     *
     * Kalau ini melempar, JANGAN dimatikan -- eager-load relasinya (`with()` /
     * `loadMissing()`), itulah perbaikannya.
     */
    private function guardAgainstNPlusOne(): void
    {
        Model::preventLazyLoading(! app()->isProduction());
    }
}
