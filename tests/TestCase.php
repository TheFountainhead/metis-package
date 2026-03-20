<?php

namespace TheFountainhead\Metis\Tests;

use Orchestra\Testbench\TestCase as BaseTestCase;
use TheFountainhead\Metis\MetisServiceProvider;

abstract class TestCase extends BaseTestCase
{
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
