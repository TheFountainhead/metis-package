<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyFunding;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeFundingHistory(): array
{
    // Resights ApS' reelle kapitalhistorik (prod-ES-verificeret 9/7-26)
    return [
        'data' => [
            'cvr' => '41527080',
            'currency' => 'DKK',
            'rounds' => [
                ['date' => '2020-07-16', 'capital' => 300000.0, 'previous_capital' => null, 'change' => null, 'change_pct' => null, 'type' => 'founding', 'amount' => 300000.0, 'kurs' => 100.0, 'payment_type' => 'cash', 'implied_valuation' => 300000.0, 'owner_changes' => [
                    ['date' => '2020-07-16', 'owner' => 'Stifter Stiftersen', 'share_pct' => 100.0, 'until' => '2020-10-22', 'is_company' => false],
                ]],
                ['date' => '2020-10-23', 'capital' => 365823.0, 'previous_capital' => 300000.0, 'change' => 65823.0, 'change_pct' => 21.9, 'type' => 'increase', 'amount' => 3500000.0, 'kurs' => 5317.4, 'payment_type' => 'debt_conversion', 'implied_valuation' => 19452000.0, 'owner_changes' => []],
                ['date' => '2025-07-25', 'capital' => 365290.0, 'previous_capital' => 365823.0, 'change' => -533.0, 'change_pct' => -0.1, 'type' => 'decrease', 'amount' => null, 'kurs' => null, 'payment_type' => null, 'implied_valuation' => null, 'owner_changes' => []],
            ],
            'ownership_events' => [],
            'valuation_series' => [
                ['date' => '2020-07-16', 'valuation' => 300000.0],
                ['date' => '2020-10-23', 'valuation' => 19452000.0],
            ],
            'summary' => [
                'round_count' => 1,
                'founding_capital' => 300000.0,
                'current_capital' => 365290.0,
                'first_date' => '2020-07-16',
                'last_date' => '2025-07-25',
                'total_funding' => 3500000.0,
                'latest_valuation' => 19452000.0,
                'latest_valuation_date' => '2020-10-23',
            ],
        ],
    ];
}

it('renders the funding rounds table with event badges', function () {
    Http::fake(['*company/41527080/funding-history*' => Http::response(fakeFundingHistory())]);

    Livewire::test(CompanyFunding::class, ['query' => '41527080'])
        ->assertSee(__('Kapitalhistorik'))
        ->assertSee(__('Stiftelse'))
        ->assertSee(__('Forhøjelse'))
        ->assertSee(__('Nedsættelse'))
        ->assertSee('365.290 DKK')
        ->assertSee('+65.823')
        ->assertSee('Stifter Stiftersen → 100%')
        ->assertSee('1 kapitaludvidelse');
});

it('renders nothing when only a founding event exists', function () {
    $single = fakeFundingHistory();
    $single['data']['rounds'] = array_slice($single['data']['rounds'], 0, 1);
    $single['data']['summary']['round_count'] = 0;

    Http::fake(['*company/41527080/funding-history*' => Http::response($single)]);

    Livewire::test(CompanyFunding::class, ['query' => '41527080'])
        ->assertDontSee(__('Kapitalhistorik'));
});

it('skips fetching for non-CVR queries', function () {
    Http::fake();

    Livewire::test(CompanyFunding::class, ['query' => 'Resights ApS'])
        ->assertSet('rounds', []);

    Http::assertNothingSent();
});

it('renders Phase 2 amounts, valuations, payment types and chart island', function () {
    Http::fake(['*company/41527080/funding-history*' => Http::response(fakeFundingHistory())]);

    Livewire::test(CompanyFunding::class, ['query' => '41527080'])
        ->assertSee('Rejst i alt')
        ->assertSee('3.500.000 DKK')
        ->assertSee(__('Konvertering af gæld'))
        ->assertSee(__('Implied valuation'))
        ->assertSee('19.452.000 DKK')
        ->assertSee(__('Seneste implied valuation'))
        ->assertSeeHtml('data-metis-funding-chart');
});
