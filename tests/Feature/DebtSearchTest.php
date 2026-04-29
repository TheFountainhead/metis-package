<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\DebtSearch;

function fakeDebtSearchResponse(array $overrides = []): array
{
    return array_merge([
        'summary' => [
            'n_loans' => 2,
            'n_properties' => 2,
            'n_companies' => 2,
            'n_creditors' => 2,
            'total_principal_kr' => 10_000_000,
            'avg_rate' => 9.5,
        ],
        'creditors' => [
            ['creditor' => 'HKL Pantebrevs Invest', 'n_loans' => 5, 'avg_rate' => 9.1, 'max_rate' => 11.5, 'total_principal_kr' => 8_000_000],
        ],
        'results' => [
            [
                'mortgage_id' => 1,
                'property' => ['id' => 100, 'address' => 'Tonsbakken 14A', 'postal_code' => '2740'],
                'owners' => [['type' => 'company', 'id' => 50, 'name' => 'Tonsbakken 14 A ApS', 'cvr' => '12345678', 'ownership_share_pct' => 100]],
                'debt' => ['type' => 'ejerpantebrev', 'interest_rate' => 9.5, 'principal_amount_kr' => 5_000_000, 'creditor' => 'HKL Pantebrevs Invest', 'registration_date' => '2020-01-01'],
            ],
        ],
        'pagination' => ['next_cursor' => null, 'limit' => 25, 'has_more' => false],
        'meta' => ['aggregate_ms' => 30, 'query_ms' => 50, 'coverage_disclaimer' => 'Tinglysning-coverage: ~33%'],
    ], $overrides);
}

it('mounts without firing API call when filters are at defaults', function () {
    Http::fake();

    Livewire::test(DebtSearch::class)
        ->assertSet('hasSearched', false)
        ->assertSee('Hvad leder du efter?');

    Http::assertNothingSent();
});

it('mounts with non-default filters fires search', function () {
    Http::fake(['*/v1/debt-search*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::withQueryParams(['rate_min' => 10.0])
        ->test(DebtSearch::class)
        ->assertSet('hasSearched', true)
        ->assertSet('minRate', 10.0)
        ->assertSee('Tonsbakken 14A');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/debt-search')
        && $r->data()['min_rate'] == 10.0);
});

it('updating a filter triggers search and resets cursor + history', function () {
    Http::fake(['*/v1/debt-search*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('cursor', 'previous-page-cursor')
        ->set('cursorHistory', ['old-cursor'])
        ->set('postalCodeFrom', '2000')
        ->assertSet('cursor', null)
        ->assertSet('cursorHistory', [])
        ->assertSet('hasSearched', true);
});

it('postalCodeFrom + postalCodeTo are sent as separate filters', function () {
    Http::fake(['*/v1/debt-search*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '9000')
        ->set('postalCodeTo', '9499');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/debt-search')
        && ($r->data()['postal_code_from'] ?? null) === '9000'
        && ($r->data()['postal_code_to'] ?? null) === '9499');
});

it('previousPage pops cursorHistory and re-searches', function () {
    Http::fake(['*/v1/debt-search*' => Http::response(fakeDebtSearchResponse([
        'pagination' => ['next_cursor' => 'page-2-cursor', 'limit' => 25, 'has_more' => true],
    ]))]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '2000')
        ->call('nextPage')
        ->assertSet('cursor', 'page-2-cursor')
        ->assertSet('cursorHistory', [null])
        ->call('previousPage')
        ->assertSet('cursor', null)
        ->assertSet('cursorHistory', []);
});

it('previousPage does nothing when cursorHistory is empty', function () {
    Http::fake(['*/v1/debt-search*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '2000')
        ->call('previousPage')
        ->assertSet('cursor', null);
});

it('renders empty state when API returns zero loans', function () {
    Http::fake([
        '*/v1/debt-search*' => Http::response(fakeDebtSearchResponse(['summary' => [
            'n_loans' => 0, 'n_properties' => 0, 'n_companies' => 0, 'n_creditors' => 0,
            'total_principal_kr' => 0, 'avg_rate' => 0,
        ]])),
    ]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '9999')
        ->assertSee('Ingen lån matcher dine filtre');
});

it('renders error state on API failure', function () {
    Http::fake(['*/v1/debt-search*' => Http::response([], 500)]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '2000')
        ->assertSet('error', 'Søgetjenesten er midlertidigt utilgængelig')
        ->assertSee('midlertidigt utilgængelig');
});

it('renders quota-exceeded state on 429', function () {
    Http::fake(['*/v1/debt-search*' => Http::response(['error' => 'daily_quota_exceeded'], 429)]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '2000')
        ->assertSet('quotaExceeded', true)
        ->assertSee('søgekvote');
});

it('downloadCsv dispatches event with signed URL on success', function () {
    Http::fake([
        '*/v1/debt-search/export-link' => Http::response(['url' => 'https://api.test/csv?signature=abc', 'expires_at' => '2026-04-29T12:00:00Z']),
    ]);

    Livewire::test(DebtSearch::class)
        ->call('downloadCsv')
        ->assertDispatched('debt-search:download', url: 'https://api.test/csv?signature=abc');
});

it('downloadCsv shows error when export ability missing', function () {
    Http::fake(['*/v1/debt-search/export-link' => Http::response(['error' => 'forbidden'], 403)]);

    Livewire::test(DebtSearch::class)
        ->call('downloadCsv')
        ->assertSet('error', 'CSV-eksport er ikke tilgængelig for din konto.');
});

it('resetFilters clears state to defaults', function () {
    Http::fake();

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '2000')
        ->set('postalCodeTo', '2999')
        ->set('debtType', 'ejerpantebrev')
        ->call('resetFilters')
        ->assertSet('postalCodeFrom', null)
        ->assertSet('postalCodeTo', null)
        ->assertSet('debtType', null)
        ->assertSet('cursorHistory', [])
        ->assertSet('hasSearched', false)
        ->assertSet('response', null);
});

it('nextPage uses next_cursor and pushes current cursor onto history', function () {
    Http::fake(['*/v1/debt-search*' => Http::response(fakeDebtSearchResponse([
        'pagination' => ['next_cursor' => 'page-2-cursor.signature', 'limit' => 25, 'has_more' => true],
    ]))]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '2000')
        ->call('nextPage')
        ->assertSet('cursor', 'page-2-cursor.signature')
        ->assertSet('cursorHistory', [null]);

    Http::assertSent(fn ($r) =>
        isset($r->data()['cursor']) && $r->data()['cursor'] === 'page-2-cursor.signature'
    );
});
