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

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('metis.registry_api.url', 'https://registry-api.test');
        $app['config']->set('metis.registry_api.key', 'test-api-key');
    }
}
