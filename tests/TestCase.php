<?php

namespace TheFountainhead\Metis\Tests;

use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as BaseTestCase;
use TheFountainhead\Metis\MetisServiceProvider;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Enhver ikke-fake'et HTTP-request fejler straks i stedet for at ramme
        // netværket, så endpoint-drift bliver en præcis fejl frem for en DNS-timeout.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            MetisServiceProvider::class,
        ];
    }

    /**
     * Pakkens egne ruter.
     *
     * Testbench loader dem ikke af sig selv, saa `route('metis.lookup')` kaster
     * RouteNotFoundException i tests — selv om ruten findes i prod. Uden dette
     * kan en redirect mellem pakkens egne sider ikke testes overhovedet.
     */
    protected function defineRoutes($router): void
    {
        require __DIR__.'/../routes/web.php';
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('metis.registry_api.url', 'https://registry-api.test');
        $app['config']->set('metis.registry_api.key', 'test-api-key');
    }
}
