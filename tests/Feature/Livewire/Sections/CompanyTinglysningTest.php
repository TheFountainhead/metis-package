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

// === Task 12: filter UX, polling-cursor refinement, drawer URL-binding ===

it('changing status filter resets cursor + mortgages and triggers fresh fetch', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::sequence()
            ->push(fakeTinglysningOverview([
                'streaming' => [
                    'complete' => false,
                    'cursor' => 'cursor-A',
                    'total_expected' => 10,
                    'delivered_so_far' => 1,
                ],
            ]))
            ->push(fakeTinglysningOverview([
                'mortgages_added' => [], // inactive returns no rows
                'streaming' => [
                    'complete' => true,
                    'cursor' => null,
                    'total_expected' => 0,
                    'delivered_so_far' => 0,
                ],
            ])),
    ]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('streaming', true)
        ->assertSet('cursor', 'cursor-A')
        ->assertCount('mortgages', 1);

    $component->set('status', 'inactive')
        ->assertSet('cursor', null)
        ->assertSet('streaming', false)
        ->assertCount('mortgages', 0);

    // Second request should include status=inactive
    Http::assertSent(function ($request) {
        return str_contains((string) $request->url(), 'tinglysning-overview')
            && str_contains((string) $request->url(), 'status=inactive');
    });
});

it('polling continues when streaming.complete=false and stops when true', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::sequence()
            ->push(fakeTinglysningOverview([
                'streaming' => [
                    'complete' => false,
                    'cursor' => 'c1',
                    'total_expected' => 3,
                    'delivered_so_far' => 1,
                ],
            ]))
            ->push(fakeTinglysningOverview([
                'mortgages_added' => [[
                    'id' => 10001,
                    'address' => 'Andengade 5',
                    'mortgage_type' => 'realkreditpantebrev',
                    'creditor' => 'X',
                    'principal_amount' => 100_000_00,
                    'is_sampant' => false,
                ]],
                'streaming' => [
                    'complete' => false,
                    'cursor' => 'c2',
                    'total_expected' => 3,
                    'delivered_so_far' => 2,
                ],
            ]))
            ->push(fakeTinglysningOverview([
                'mortgages_added' => [[
                    'id' => 10002,
                    'address' => 'Tredjevej 7',
                    'mortgage_type' => 'ejerpantebrev',
                    'creditor' => 'Y',
                    'principal_amount' => 200_000_00,
                    'is_sampant' => false,
                ]],
                'streaming' => [
                    'complete' => true,
                    'cursor' => null,
                    'total_expected' => 3,
                    'delivered_so_far' => 3,
                ],
            ])),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('streaming', true)
        ->assertSet('cursor', 'c1')
        ->call('pollForUpdates')
        ->assertSet('streaming', true)
        ->assertSet('cursor', 'c2')
        ->assertCount('mortgages', 2)
        ->call('pollForUpdates')
        ->assertSet('streaming', false)
        ->assertSet('cursor', null)
        ->assertCount('mortgages', 3);
});

it('openMortgageId is URL-bound and triggers loadChangeHistory on open', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview()),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->assertSet('openMortgageId', null)
        ->assertSet('changeHistory', null)
        ->set('openMortgageId', 9999)
        ->assertSet('openMortgageId', 9999)
        ->assertSet('changeHistory', []) // stub returns empty array
        ->set('openMortgageId', null)
        ->assertSet('changeHistory', null);
});

it('navigateDrawer next/prev advances and clamps at boundaries', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview([
            'mortgages_added' => [
                ['id' => 100, 'address' => 'A', 'mortgage_type' => 'ejerpantebrev', 'is_sampant' => false],
                ['id' => 200, 'address' => 'B', 'mortgage_type' => 'ejerpantebrev', 'is_sampant' => false],
                ['id' => 300, 'address' => 'C', 'mortgage_type' => 'ejerpantebrev', 'is_sampant' => false],
            ],
            'streaming' => ['complete' => true, 'cursor' => null, 'total_expected' => 3, 'delivered_so_far' => 3],
        ])),
    ]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->set('openMortgageId', 200);

    // Next
    $component->call('navigateDrawer', 'next')
        ->assertSet('openMortgageId', 300);

    // Boundary: at last, should NOT wrap around
    $component->call('navigateDrawer', 'next')
        ->assertSet('openMortgageId', 300);

    // Prev twice
    $component->call('navigateDrawer', 'prev')
        ->assertSet('openMortgageId', 200)
        ->call('navigateDrawer', 'prev')
        ->assertSet('openMortgageId', 100);

    // Boundary: at first, should NOT go below
    $component->call('navigateDrawer', 'prev')
        ->assertSet('openMortgageId', 100);
});

it('clearFilters resets all filter state and re-fetches', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview()),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->set('status', 'inactive')
        ->set('mortgageTypeFilter', ['ejerpantebrev'])
        ->set('minAmount', 1000000)
        ->set('sortBy', 'tinglysning_date_desc')
        ->call('clearFilters')
        ->assertSet('status', 'active')
        ->assertSet('mortgageTypeFilter', [])
        ->assertSet('minAmount', null)
        ->assertSet('sortBy', 'principal_amount_desc');
});

it('sends mortgage_types and amount range as ører to registry-api', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview()),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->set('mortgageTypeFilter', ['privatpantebrev', 'realkreditpantebrev'])
        ->set('minAmount', 5000) // 5000 DKK
        ->set('maxAmount', 1000000); // 1M DKK

    Http::assertSent(function ($request) {
        $url = (string) $request->url();
        return str_contains($url, 'mortgage_types')
            && str_contains($url, 'min_amount=500000')   // 5000 DKK × 100 = 500000 ører
            && str_contains($url, 'max_amount=100000000'); // 1M DKK × 100
    });
});

// === Task 13: XLSX export ===

it('exportXlsx returns a streamed XLSX response with correct content-type and filename', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview()),
    ]);

    Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->call('exportXlsx')
        ->assertFileDownloaded(contentType: 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
});

it('exportXlsx filename embeds CVR + ISO date', function () {
    Http::fake([
        '*tinglysning-overview*' => Http::response(fakeTinglysningOverview()),
    ]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->call('exportXlsx');

    $download = $component->effects['download'] ?? null;
    expect($download)->not->toBeNull();
    expect($download['name'])
        ->toStartWith('tinglysning-28963610-')
        ->toEndWith('.xlsx');
});

it('exportXlsx XLSX summary total matches frontend-computed totals from mortgages (consistency assertion)', function () {
    // Build a fixture where tree_meta matches the mortgages list (no sampant)
    // so that frontend-computed total == tree_meta total. This is the killer-feature
    // consistency property: XLSX never disagrees with the UI.
    $consistentFixture = fakeTinglysningOverview([
        'mortgages_added' => [
            [
                'id' => 1,
                'tinglysning_right_id' => 'uuid-1',
                'address' => 'Vej 1',
                'mortgage_type' => 'realkreditpantebrev',
                'creditor' => 'X',
                'principal_amount' => 1_000_000_00, // 1M DKK
                'is_sampant' => false,
            ],
            [
                'id' => 2,
                'tinglysning_right_id' => 'uuid-2',
                'address' => 'Vej 2',
                'mortgage_type' => 'realkreditpantebrev',
                'creditor' => 'Y',
                'principal_amount' => 2_500_000_00, // 2.5M DKK
                'is_sampant' => false,
            ],
        ],
        'tree_meta' => [
            'total_descendant_companies' => 1,
            'total_properties' => 2,
            'total_mortgages' => 2,
            'total_principal_amount' => 3_500_000_00, // 3.5M DKK in ører
            'weighted_ltv' => 0.6,
        ],
    ]);

    Http::fake([
        '*tinglysning-overview*' => Http::response($consistentFixture),
    ]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '28963610']);

    $treeMetaTotalOere = $component->get('treeMeta')['total_principal_amount'];
    $mortgages = $component->get('mortgages');

    // Frontend-computed totals (DISTINCT on UUID) — must match tree_meta when no sampant
    $totals = \TheFountainhead\Metis\Exports\PortfolioTinglysningExport::computeTotalsFromMortgages($mortgages);
    expect($totals['total_principal_amount_oere'])->toBe($treeMetaTotalOere);

    // Now invoke export and verify the XLSX cell B3 value matches
    $component->call('exportXlsx');

    $download = $component->effects['download'] ?? null;
    expect($download)->not->toBeNull();

    $tmp = tempnam(sys_get_temp_dir(), 'export-test-') . '.xlsx';
    file_put_contents($tmp, base64_decode($download['content']));
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
    @unlink($tmp);

    $expectedDkk = $treeMetaTotalOere / 100;
    expect((float) $spreadsheet->getSheetByName('Oversigt')->getCell('B3')->getValue())
        ->toBe((float) $expectedDkk);
});

it('exportXlsx Pantebreve sheet has one row per mortgage with sampant flag preserved', function () {
    // 3 mortgages, 2 share UUID (sampant), 1 unique → all 3 rendered as rows,
    // but Oversigt total counts the sampant-pair once.
    $sampantFixture = fakeTinglysningOverview([
        'mortgages_added' => [
            ['id' => 1, 'tinglysning_right_id' => 'uuid-shared', 'address' => 'A', 'mortgage_type' => 'realkreditpantebrev', 'principal_amount' => 1_000_000_00, 'is_sampant' => true],
            ['id' => 2, 'tinglysning_right_id' => 'uuid-shared', 'address' => 'B', 'mortgage_type' => 'realkreditpantebrev', 'principal_amount' => 1_000_000_00, 'is_sampant' => true],
            ['id' => 3, 'tinglysning_right_id' => 'uuid-solo', 'address' => 'C', 'mortgage_type' => 'ejerpantebrev', 'principal_amount' => 500_000_00, 'is_sampant' => false],
        ],
    ]);

    Http::fake([
        '*tinglysning-overview*' => Http::response($sampantFixture),
    ]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '28963610'])
        ->call('exportXlsx');

    $download = $component->effects['download'];
    $tmp = tempnam(sys_get_temp_dir(), 'export-test-') . '.xlsx';
    file_put_contents($tmp, base64_decode($download['content']));
    $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($tmp);
    @unlink($tmp);

    // Pantebreve sheet: 1 header + 3 data rows
    $pantebreve = $spreadsheet->getSheetByName('Pantebreve');
    expect($pantebreve->getHighestDataRow())->toBe(4);

    // Sampant flag in column J (4th column from end of 12: J=10)
    expect($pantebreve->getCell('J2')->getValue())->toBe('Ja');
    expect($pantebreve->getCell('J3')->getValue())->toBe('Ja');
    expect($pantebreve->getCell('J4')->getValue())->toBe('Nej');

    // Oversigt: total = 1.5M DKK (uuid-shared counted ONCE + uuid-solo)
    $oversigt = $spreadsheet->getSheetByName('Oversigt');
    expect((float) $oversigt->getCell('B3')->getValue())->toBe(1_500_000.0);

    // Unique mortgages = 2; sampant-count = 1
    expect((int) $oversigt->getCell('B4')->getValue())->toBe(2);
    expect((int) $oversigt->getCell('B8')->getValue())->toBe(1);
});
