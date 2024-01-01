<?php

namespace Coreproc\NovaDataSync;

use Coreproc\NovaDataSync\Http\Middleware\Authorize;
use Coreproc\NovaDataSync\Nova\Import;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Laravel\Nova\Events\ServingNova;
use Laravel\Nova\Http\Middleware\Authenticate;
use Laravel\Nova\Nova;

class ToolServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->app->booted(function () {
            $this->routes();
        });

        // Publis migration files
        $this->publishes([
            __DIR__ . '/../database/migrations' => database_path('migrations'),
        ], 'nova-data-sync-migrations');

        // Publish config file
        $this->publishes([
            __DIR__ . '/../config/nova-data-sync.php' => config_path('nova-data-sync.php'),
        ], 'nova-data-sync-config');

        Nova::serving(function (ServingNova $event) {
            Nova::resources([Import::class]);
        });
    }

    /**
     * Register the tool's routes.
     *
     * @return void
     */
    protected function routes()
    {
        if ($this->app->routesAreCached()) {
            return;
        }

        Nova::router(['nova', Authenticate::class, Authorize::class], 'nova-data-sync')
            ->group(__DIR__ . '/../routes/inertia.php');

        Route::middleware(['nova', Authorize::class])
            ->prefix('nova-vendor/nova-data-sync')
            ->group(__DIR__ . '/../routes/api.php');
    }

    /**
     * Register any application services.
     *
     * @return void
     */
    public function register()
    {
        //
    }
}
