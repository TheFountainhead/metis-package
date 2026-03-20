<?php

namespace TheFountainhead\Metis;

use Illuminate\Support\ServiceProvider;
use Laravel\Socialite\Facades\Socialite;
use Livewire\Livewire;

class MetisServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/metis.php', 'metis');

        $this->app->singleton('metis', function ($app) {
            return new \TheFountainhead\Metis\Services\RegistryApi(
                config('metis.registry_api.url'),
                config('metis.registry_api.key'),
            );
        });
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'metis');

        if (config('metis.mode') === 'standalone') {
            static::standaloneRoutes();
        }

        $this->registerPublishing();
        $this->registerLivewireComponents();
        $this->registerCriiptoDriver();
    }

    public static function standaloneRoutes(): void
    {
        $routesPath = __DIR__.'/../routes/web.php';

        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }

    public static function embeddedRoutes(): void
    {
        $routesPath = __DIR__.'/../routes/embedded.php';

        if (file_exists($routesPath)) {
            require $routesPath;
        }
    }

    protected function registerPublishing(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/metis.php' => config_path('metis.php'),
            ], 'metis-config');

            $this->publishes([
                __DIR__.'/../resources/views' => resource_path('views/vendor/metis'),
            ], 'metis-views');

            $this->publishes([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'metis-migrations');
        }
    }

    protected function registerLivewireComponents(): void
    {
        if (! class_exists(Livewire::class)) {
            return;
        }

        // Components will be registered here as they are created in later tasks.
        // Pattern: Livewire::component('metis-component-name', ComponentClass::class);
    }

    protected function registerCriiptoDriver(): void
    {
        if (! config('metis.admin.enabled')) {
            return;
        }

        if (! class_exists(Socialite::class)) {
            return;
        }

        // Criipto Socialite driver will be registered here in the admin auth task.
    }
}
