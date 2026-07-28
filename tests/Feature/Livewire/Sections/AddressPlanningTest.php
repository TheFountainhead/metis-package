<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressPlanning;

// property.local_plans is a wrapper (plans/local_plans/zone_status/queried_at/...),
// not a plan list. Reading it directly and count()'ing it rendered N empty
// "Local plan" cards where N = number of wrapper keys — on success (6 keys)
// and, once registry-api ships the error-wrapper shape from PR #199, on an
// upstream failure too (2 keys), falsely claiming plans exist when the
// lookup actually failed.
it('renders exactly the plans in the plans key on a successful response', function () {
    Http::fake(['*registry-api.test/v1/property/analysis*' => Http::response(['data' => [
        'property' => [
            'local_plans' => [
                'plans' => [
                    ['plan_id' => '123', 'plan_name' => 'Lokalplan 123 - Boligområde'],
                ],
                'local_plans' => [
                    ['plan_id' => '123', 'plan_name' => 'Lokalplan 123 - Boligområde'],
                ],
                'zone_status' => 'byzone',
                'queried_at' => now()->toIso8601String(),
                'query_point' => ['lat' => 55.0, 'lng' => 12.0],
                'coverage' => 'vedtaget',
            ],
        ],
    ]])]);

    Livewire::test(AddressPlanning::class, ['query' => 'Testvej 1, 2100 København Ø'])
        ->assertSet('plans', [
            ['plan_id' => '123', 'plan_name' => 'Lokalplan 123 - Boligområde'],
        ])
        ->assertSee('Lokalplan 123 - Boligområde')
        ->assertDontSee('No planning data found');
});

it('renders the empty state, not fake plan cards, on an upstream error wrapper', function () {
    Http::fake(['*registry-api.test/v1/property/analysis*' => Http::response(['data' => [
        'property' => [
            'local_plans' => [
                'error' => 'upstream_error',
                'status' => 500,
            ],
        ],
    ]])]);

    Livewire::test(AddressPlanning::class, ['query' => 'Testvej 1, 2100 København Ø'])
        ->assertSet('plans', [])
        ->assertSee('No planning data found')
        ->assertDontSee('Local plan');
});
