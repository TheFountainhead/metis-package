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

        $this->app->booted(function () {
            \Illuminate\Support\Facades\Blade::component('metis-link', \TheFountainhead\Metis\View\Components\MetisLink::class);
        });

        $this->registerPublishing();
        $this->registerLivewireComponents();
        $this->registerCriiptoDriver();
    }

    public static function standaloneRoutes(): void
    {
        $routesPath = __DIR__.'/../routes/web.php';

        if (file_exists($routesPath)) {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group($routesPath);
        }
    }

    public static function embeddedRoutes(): void
    {
        $routesPath = __DIR__.'/../routes/embedded.php';

        if (file_exists($routesPath)) {
            \Illuminate\Support\Facades\Route::middleware('web')
                ->group($routesPath);
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

        // Admin components
        Livewire::component('metis-admin-dashboard', \TheFountainhead\Metis\Livewire\Admin\Dashboard::class);
        Livewire::component('metis-admin-leads', \TheFountainhead\Metis\Livewire\Admin\Leads::class);
        Livewire::component('metis-admin-logs', \TheFountainhead\Metis\Livewire\Admin\Logs::class);

        // Address sections
        Livewire::component('metis-address-bbr', \TheFountainhead\Metis\Livewire\Sections\AddressBbr::class);
        Livewire::component('metis-address-companies', \TheFountainhead\Metis\Livewire\Sections\AddressCompanies::class);
        Livewire::component('metis-address-heritage', \TheFountainhead\Metis\Livewire\Sections\AddressHeritage::class);
        Livewire::component('metis-address-mortgages', \TheFountainhead\Metis\Livewire\Sections\AddressMortgages::class);
        Livewire::component('metis-address-owners', \TheFountainhead\Metis\Livewire\Sections\AddressOwners::class);
        Livewire::component('metis-address-planning', \TheFountainhead\Metis\Livewire\Sections\AddressPlanning::class);
        Livewire::component('metis-address-transactions', \TheFountainhead\Metis\Livewire\Sections\AddressTransactions::class);
        Livewire::component('metis-address-valuation', \TheFountainhead\Metis\Livewire\Sections\AddressValuation::class);
        Livewire::component('metis-address-comparison', \TheFountainhead\Metis\Livewire\Sections\AddressComparison::class);

        // Company sections
        Livewire::component('metis-company-info', \TheFountainhead\Metis\Livewire\Sections\CompanyInfo::class);
        Livewire::component('metis-company-roles', \TheFountainhead\Metis\Livewire\Sections\CompanyRoles::class);
        Livewire::component('metis-company-structure', \TheFountainhead\Metis\Livewire\Sections\CompanyStructure::class);
        Livewire::component('metis-company-properties', \TheFountainhead\Metis\Livewire\Sections\CompanyProperties::class);
        Livewire::component('metis-company-tax', \TheFountainhead\Metis\Livewire\Sections\CompanyTax::class);

        // Person sections
        Livewire::component('metis-person-summary', \TheFountainhead\Metis\Livewire\Sections\PersonSummary::class);
        Livewire::component('metis-person-info', \TheFountainhead\Metis\Livewire\Sections\PersonInfo::class);
        Livewire::component('metis-person-network', \TheFountainhead\Metis\Livewire\Sections\PersonNetwork::class);
        Livewire::component('metis-person-companies', \TheFountainhead\Metis\Livewire\Sections\PersonCompanies::class);
        Livewire::component('metis-person-roles', \TheFountainhead\Metis\Livewire\Sections\PersonRoles::class);
        Livewire::component('metis-person-properties', \TheFountainhead\Metis\Livewire\Sections\PersonProperties::class);
        Livewire::component('metis-person-relations', \TheFountainhead\Metis\Livewire\Sections\PersonRelations::class);

        // Map
        Livewire::component('metis-map-panel', \TheFountainhead\Metis\Livewire\MapPanel::class);

        // Core components
        Livewire::component('metis-search', \TheFountainhead\Metis\Livewire\Search::class);
        Livewire::component('metis-email-gate', \TheFountainhead\Metis\Livewire\EmailGate::class);
        Livewire::component('metis-index', \TheFountainhead\Metis\Livewire\Index::class);
        Livewire::component('metis-lookup', \TheFountainhead\Metis\Livewire\Lookup::class);
    }

    protected function registerCriiptoDriver(): void
    {
        if (! config('metis.admin.enabled')) {
            return;
        }

        if (! class_exists(Socialite::class)) {
            return;
        }

        $socialite = $this->app->make(\Laravel\Socialite\Contracts\Factory::class);
        $socialite->extend('criipto', function () use ($socialite) {
            $config = config('services.criipto');

            return $socialite->buildProvider(\TheFountainhead\Metis\Auth\SocialiteCriiptoProvider::class, $config);
        });
    }
}
