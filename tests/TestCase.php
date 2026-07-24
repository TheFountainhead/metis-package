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
        // netvaerket. Uden denne guard bliver en endpoint-omdoebning til en
        // 10s DNS-timeout mod registry-api.test frem for en praecis fejl, og
        // driften opdages foerst i CI paa en urelateret branch.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            \Livewire\LivewireServiceProvider::class,
            MetisServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('metis.registry_api.url', 'https://registry-api.test');
        $app['config']->set('metis.registry_api.key', 'test-api-key');
    }
}
