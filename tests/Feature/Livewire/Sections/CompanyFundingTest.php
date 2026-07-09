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
                ['date' => '2020-07-16', 'capital' => 300000.0, 'previous_capital' => null, 'change' => null, 'change_pct' => null, 'type' => 'founding', 'owner_changes' => [
                    ['date' => '2020-07-16', 'owner' => 'Stifter Stiftersen', 'share_pct' => 100.0, 'until' => '2020-10-22', 'is_company' => false],
                ]],
                ['date' => '2020-10-23', 'capital' => 365823.0, 'previous_capital' => 300000.0, 'change' => 65823.0, 'change_pct' => 21.9, 'type' => 'increase', 'owner_changes' => []],
                ['date' => '2025-07-25', 'capital' => 365290.0, 'previous_capital' => 365823.0, 'change' => -533.0, 'change_pct' => -0.1, 'type' => 'decrease', 'owner_changes' => []],
            ],
            'ownership_events' => [],
            'summary' => [
                'round_count' => 1,
                'founding_capital' => 300000.0,
                'current_capital' => 365290.0,
                'first_date' => '2020-07-16',
                'last_date' => '2025-07-25',
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
