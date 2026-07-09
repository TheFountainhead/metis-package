<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\AddressMortgages;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeAnalysisWithMortgages(int $totalDebt, ?int $estimatedValue): array
{
    return [
        'data' => [
            'property' => [
                'mortgages' => [
                    ['creditor' => 'Realkredit Danmark', 'principal' => $totalDebt, 'interest_rate' => 4.5],
                ],
                'total_debt' => $totalDebt,
                'valuation' => $estimatedValue === null ? null : [
                    'estimated_value' => $estimatedValue,
                ],
            ],
        ],
    ];
}

it('computes LTV against the public valuation and shows a green badge below 60%', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithMortgages(5_000_000, 10_000_000)),
    ]);

    Livewire::test(AddressMortgages::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSet('ltv', 50.0)
        ->assertSee('LTV 50,0%')
        ->assertSeeHtml('color="green"');
});

it('shows a yellow badge between 60% and 80%', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithMortgages(7_000_000, 10_000_000)),
    ]);

    Livewire::test(AddressMortgages::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSet('ltv', 70.0)
        ->assertSeeHtml('color="yellow"');
});

it('shows a red badge above 80%', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithMortgages(9_000_000, 10_000_000)),
    ]);

    Livewire::test(AddressMortgages::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSet('ltv', 90.0)
        ->assertSeeHtml('color="red"');
});

it('treats exactly 60% as yellow', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithMortgages(6_000_000, 10_000_000)),
    ]);

    Livewire::test(AddressMortgages::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSet('ltv', 60.0)
        ->assertSeeHtml('color="yellow"');
});

it('treats exactly 80% as yellow', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithMortgages(8_000_000, 10_000_000)),
    ]);

    Livewire::test(AddressMortgages::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSet('ltv', 80.0)
        ->assertSeeHtml('color="yellow"');
});

it('omits the LTV badge when no valuation exists', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithMortgages(5_000_000, null)),
    ]);

    Livewire::test(AddressMortgages::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSet('ltv', null)
        ->assertDontSee('LTV');
});

it('shows the principal-vs-outstanding-debt disclaimer when mortgages exist', function () {
    Http::fake([
        '*property/analysis*' => Http::response(fakeAnalysisWithMortgages(5_000_000, 10_000_000)),
    ]);

    Livewire::test(AddressMortgages::class, ['query' => 'Bredgade 40, 1260'])
        ->assertSee(__('Registered principal is not the same as current outstanding debt.'));
});
