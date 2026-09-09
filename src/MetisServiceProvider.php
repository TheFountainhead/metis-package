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
        $this->registerCommands();
    }

    /**
     * 🪤 BAADE registrering OG planlaegning. En kommando der kun registreres,
     * skal koeres i haanden — og en opbevaringsgraense ingen husker at koere er
     * ingen opbevaringsgraense. `metis_lookups` voksede uroert fra 25. marts
     * til 9. august (8.265 raekker, alle med IP) netop fordi oprydningen ikke
     * fandtes.
     */
    protected function registerCommands(): void
    {
        if (! $this->app->runningInConsole()) {
            return;
        }

        $this->commands([
            \TheFountainhead\Metis\Console\Commands\PilotAccount::class,
            \TheFountainhead\Metis\Console\Commands\PruneMetisLookups::class,
            \TheFountainhead\Metis\Console\Commands\GrantLookupQuota::class,
            \TheFountainhead\Metis\Console\Commands\ReportAbandonedVerifications::class,
        ]);

        $this->app->booted(function () {
            $schedule = $this->app->make(\Illuminate\Console\Scheduling\Schedule::class);

            $schedule->command('metis:prune-lookups')
                ->dailyAt('03:30')
                ->onOneServer()
                ->withoutOverlapping();

            // 🪤 Kl. 08 frem for om natten: rapporten er noget Frederik skal
            // HANDLE paa, og en mail kl. 03:30 er laest og glemt inden
            // arbejdsdagen. Kommandoen sender kun naar der ER frafaldne.
            $schedule->command('metis:report-abandoned')
                ->dailyAt('08:00')
                ->onOneServer()
                ->withoutOverlapping();
        });
    }

    /**
     * 🪤 `NoIndex` staar IKKE her, men paa rutegruppen inde i rutefilerne.
     * `tests/TestCase.php` inkluderer rutefilerne direkte og springer denne
     * provider over — middleware registreret her ville koere i prod og ikke i
     * test. Se kommentaren i `routes/web.php`.
     */
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
        // Husk-mig genskabes også på Livewire-opdateringer (/livewire/update), så
        // en side ikke flipper til "Kun for pilotbrugere" midt i en session.
        Livewire::addPersistentMiddleware([\TheFountainhead\Metis\Http\Middleware\RestorePilotSession::class]);

        Livewire::component('metis-lookup-title', \TheFountainhead\Metis\Livewire\LookupTitle::class);
        Livewire::component('metis-admin-dashboard', \TheFountainhead\Metis\Livewire\Admin\Dashboard::class);
        Livewire::component('metis-admin-leads', \TheFountainhead\Metis\Livewire\Admin\Leads::class);
        Livewire::component('metis-admin-logs', \TheFountainhead\Metis\Livewire\Admin\Logs::class);

        // Address sections
        Livewire::component('metis-address-bbr', \TheFountainhead\Metis\Livewire\Sections\AddressBbr::class);
        Livewire::component('metis-address-companies', \TheFountainhead\Metis\Livewire\Sections\AddressCompanies::class);
        Livewire::component('metis-address-energy', \TheFountainhead\Metis\Livewire\Sections\AddressEnergy::class);
        Livewire::component('metis-address-heritage', \TheFountainhead\Metis\Livewire\Sections\AddressHeritage::class);
        Livewire::component('metis-address-mortgages', \TheFountainhead\Metis\Livewire\Sections\AddressMortgages::class);
        Livewire::component('metis-address-owners', \TheFountainhead\Metis\Livewire\Sections\AddressOwners::class);
        Livewire::component('metis-address-planning', \TheFountainhead\Metis\Livewire\Sections\AddressPlanning::class);
        Livewire::component('metis-address-transactions', \TheFountainhead\Metis\Livewire\Sections\AddressTransactions::class);
        Livewire::component('metis-address-similar-trades', \TheFountainhead\Metis\Livewire\Sections\AddressSimilarTrades::class);
        Livewire::component('metis-address-skraafoto', \TheFountainhead\Metis\Livewire\Sections\AddressSkraafoto::class);
        Livewire::component('metis-address-valuation', \TheFountainhead\Metis\Livewire\Sections\AddressValuation::class);
        Livewire::component('metis-address-comparison', \TheFountainhead\Metis\Livewire\Sections\AddressComparison::class);
        Livewire::component('metis-address-comparison-detail', \TheFountainhead\Metis\Livewire\Sections\AddressComparisonDetail::class);

        // Company sections
        Livewire::component('metis-company-overview', \TheFountainhead\Metis\Livewire\Sections\CompanyOverview::class);
        Livewire::component('metis-company-info', \TheFountainhead\Metis\Livewire\Sections\CompanyInfo::class);
        Livewire::component('metis-company-roles', \TheFountainhead\Metis\Livewire\Sections\CompanyRoles::class);
        Livewire::component('metis-company-structure', \TheFountainhead\Metis\Livewire\Sections\CompanyStructure::class);
        Livewire::component('metis-company-relations', \TheFountainhead\Metis\Livewire\Sections\CompanyRelations::class);
        Livewire::component('metis-company-properties', \TheFountainhead\Metis\Livewire\Sections\CompanyProperties::class);
        Livewire::component('metis-company-tinglysning', \TheFountainhead\Metis\Livewire\Sections\CompanyTinglysning::class);
        Livewire::component('metis-company-tax', \TheFountainhead\Metis\Livewire\Sections\CompanyTax::class);
        Livewire::component('metis-company-funding', \TheFountainhead\Metis\Livewire\Sections\CompanyFunding::class);

        // Person sections
        Livewire::component('metis-person-summary', \TheFountainhead\Metis\Livewire\Sections\PersonSummary::class);
        Livewire::component('metis-person-info', \TheFountainhead\Metis\Livewire\Sections\PersonInfo::class);
        Livewire::component('metis-person-structure', \TheFountainhead\Metis\Livewire\Sections\PersonStructure::class);
        Livewire::component('metis-person-companies', \TheFountainhead\Metis\Livewire\Sections\PersonCompanies::class);
        Livewire::component('metis-person-roles', \TheFountainhead\Metis\Livewire\Sections\PersonRoles::class);
        Livewire::component('metis-person-properties', \TheFountainhead\Metis\Livewire\Sections\PersonProperties::class);
        Livewire::component('metis-person-relations', \TheFountainhead\Metis\Livewire\Sections\PersonRelations::class);

        // Map
        Livewire::component('metis-map-panel', \TheFountainhead\Metis\Livewire\MapPanel::class);

        // Core components
        Livewire::component('metis-search', \TheFountainhead\Metis\Livewire\Search::class);
        Livewire::component('metis-analysis-request', \TheFountainhead\Metis\Livewire\AnalysisRequest::class);
        Livewire::component('metis-pilot-login', \TheFountainhead\Metis\Livewire\PilotLogin::class);
        Livewire::component('metis-email-gate', \TheFountainhead\Metis\Livewire\EmailGate::class);
        Livewire::component('metis-index', \TheFountainhead\Metis\Livewire\Index::class);
        Livewire::component('metis-lookup', \TheFountainhead\Metis\Livewire\Lookup::class);
        Livewire::component('metis-debt-search', \TheFountainhead\Metis\Livewire\DebtSearch::class);
        Livewire::component('metis-property-explore', \TheFountainhead\Metis\Livewire\PropertyExplore::class);
        Livewire::component('metis-follow-button', \TheFountainhead\Metis\Livewire\FollowButton::class);
        Livewire::component('metis-person-follow-button', \TheFountainhead\Metis\Livewire\PersonFollowButton::class);
        Livewire::component('metis-alerts-inbox', \TheFountainhead\Metis\Livewire\AlertsInbox::class);
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
