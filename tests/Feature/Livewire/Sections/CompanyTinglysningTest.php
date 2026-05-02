<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyTinglysning;

// Register metis.lookup route stub for views that use <x-metis-link>.
beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeTinglysningOverview(array $overrides = []): array
{
    return array_merge([
        'company' => ['cvr' => '28963610', 'name' => 'Mimo Invest ApS'],
        'tree_meta' => [
            'total_descendant_companies' => 7,
            'total_properties' => 18,
            'total_mortgages' => 24,
            'total_principal_amount' => 24_730_000_000,
            'weighted_ltv' => 0.77,
            'tree_depth' => 3,
            'applied_tree_depth' => 1,
        ],
        'tier_breakdown' => [
            [
                'company_id' => 12345,
                'cvr' => '28963610',
                'name' => 'Mimo Invest ApS (root)',
                'depth' => 0,
                'property_count' => 4,
                'mortgage_count' => 4,
                'principal_amount' => 4_700_000_000,
                'weighted_ltv' => 0.74,
            ],
        ],
        'mortgages_added' => [
            [
                'id' => 9999,
                'property_id' => 87654,
                'address' => 'Roligedsvej 12-14, 2400 København NV',
                'bfe' => '1234567',
                'owner_company' => ['cvr' => '28963610', 'name' => 'Mimo Invest ApS'],
                'tier_depth' => 0,
                'mortgage_type' => 'ejerpantebrev',
                'creditor' => 'Mimo Hotel ApS',
                'debitor' => 'Mimo Hotel ApS',
                'priority' => 6,
                'principal_amount' => 820_000_000,
                'registration_date' => '2024-08-15',
                'is_active' => true,
                'is_sampant' => false,
                'ltv' => [
                    'value' => 0.76,
                    'method' => 'skoede_price',
                    'property_value_raw' => 6_200_000_000,
                    'property_value_indexed_2026' => 7_100_000_000,
                    'source_date' => '2022-04-12',
                ],
            ],
        ],
        'streaming' => [
            'complete' => true,
            'cursor' => null,
            'total_expected' => 1,
            'delivered_so_far' => 1,
        ],
    ], $overrides);
}

it('mounts and loads tree_meta + tier_breakdown synchronously from registry-api', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview()),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('hasError', false)
        ->assertSet('streaming', false)
        ->assertSet('treeMeta.total_mortgages', 24)
        ->assertSet('treeMeta.total_descendant_companies', 7)
        ->assertCount('tierBreakdown', 1)
        ->assertCount('mortgages', 1)
        ->assertSee('Mimo Invest ApS (root)')
        ->assertSee('Roligedsvej 12-14');
});

it('renders error-state when registry-api fails', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(['error' => 'boom'], 500),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('hasError', true)
        ->assertSee('Kunne ikke hente tinglysningsdata')
        ->assertSee('Prøv igen');
});

it('enters streaming mode when initial response is incomplete', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview([
            'streaming' => [
                'complete' => false,
                'cursor' => 'eyJhZnRlcl9pZCI6OTk5OX0=',
                'total_expected' => 24,
                'delivered_so_far' => 1,
            ],
        ])),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('streaming', true)
        ->assertSet('cursor', 'eyJhZnRlcl9pZCI6OTk5OX0=')
        ->assertSet('totalExpected', 24)
        ->assertSet('deliveredSoFar', 1);
});

it('pollForUpdates appends new mortgages and stops streaming on completion', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::sequence()
            ->push(fakeTinglysningOverview([
                'streaming' => [
                    'complete' => false,
                    'cursor' => 'cursor-1',
                    'total_expected' => 2,
                    'delivered_so_far' => 1,
                ],
            ]))
            ->push(fakeTinglysningOverview([
                'mortgages_added' => [[
                    'id' => 10000,
                    'property_id' => 99999,
                    'address' => 'Andengade 5, 8000 Aarhus',
                    'owner_company' => ['cvr' => '28963610', 'name' => 'Mimo Invest ApS'],
                    'mortgage_type' => 'realkreditpantebrev',
                    'creditor' => 'Nordea Kredit',
                    'principal_amount' => 500_000_000,
                    'registration_date' => '2025-01-10',
                    'is_active' => true,
                    'is_sampant' => false,
                    'ltv' => ['value' => 0.65, 'method' => 'public_valuation'],
                ]],
                'streaming' => [
                    'complete' => true,
                    'cursor' => null,
                    'total_expected' => 2,
                    'delivered_so_far' => 2,
                ],
            ])),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('streaming', true)
        ->assertCount('mortgages', 1)
        ->call('pollForUpdates')
        ->assertSet('streaming', false)
        ->assertCount('mortgages', 2)
        ->assertSee('Andengade 5');
});

it('pollForUpdates is no-op when not streaming', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview()),
    ]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('streaming', false);

    Http::assertSentCount(1);

    $component->call('pollForUpdates');

    // No additional HTTP call fired
    Http::assertSentCount(1);
});

it('retry clears error-state and re-fetches', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::sequence()
            ->push(['error' => 'boom'], 500)
            ->push(fakeTinglysningOverview()),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('hasError', true)
        ->call('retry')
        ->assertSet('hasError', false)
        ->assertSet('treeMeta.total_mortgages', 24);
});
