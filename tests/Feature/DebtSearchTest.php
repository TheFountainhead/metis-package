<?php


use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\DebtSearch;

// /soeg er kun for pilotbrugere: testene simulerer en pilot-session.
beforeEach(fn () => session(['metis_user_token' => '19|abc']));

// Søge-fakes bruger '*/v1/debt-search?*' og IKKE '*/v1/debt-search*'.
// Det brede mønster matcher nemlig også '/v1/debt-search/export-link', og
// Laravel vælger den FØRSTE matchende fake (PendingRequest ->first()), så
// en søge-fake listet før export-faken ville stjæle export-kaldet.
// Søgning er altid GET med query-filtre, export altid POST uden query —
// '?*' skiller dem derfor præcist, uanset rækkefølge. Bemærk at Str::is
// kun behandler '*' som wildcard; '?' er et literalt tegn og matcher
// netop query-separatoren.

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
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::withQueryParams(['rate_min' => 10.0])
        ->test(DebtSearch::class)
        ->assertSet('hasSearched', true)
        ->assertSet('minRate', 10.0)
        ->assertSee('Tonsbakken 14A');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/debt-search')
        && $r->data()['min_rate'] == 10.0);
});

it('updating a filter triggers search and resets cursor + history', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('cursor', 'previous-page-cursor')
        ->set('cursorHistory', ['old-cursor'])
        ->set('postalCodeFrom', '2000')
        ->assertSet('cursor', null)
        ->assertSet('cursorHistory', [])
        ->assertSet('hasSearched', true);
});

it('postalCodeFrom + postalCodeTo are sent as separate filters', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '9000')
        ->set('postalCodeTo', '9499');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/debt-search')
        && ($r->data()['postal_code_from'] ?? null) === '9000'
        && ($r->data()['postal_code_to'] ?? null) === '9499');
});

it('strips partial postal codes (1-3 digits) from API call', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '1');

    Http::assertSent(fn ($r) =>
        str_contains($r->url(), '/v1/debt-search')
        && ! isset($r->data()['postal_code_from'])
    );
});

it('strips creditor_contains shorter than 3 chars', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('creditorContains', 'ab');

    Http::assertSent(fn ($r) =>
        str_contains($r->url(), '/v1/debt-search')
        && ! isset($r->data()['creditor_contains'])
    );
});

it('422 from server does not surface as service-unavailable error', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(['error' => 'invalid filter'], 422)]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '9000')
        ->assertSet('error', null)
        ->assertSet('quotaExceeded', false);
});

it('previousPage pops cursorHistory and re-searches', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse([
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
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '2000')
        ->call('previousPage')
        ->assertSet('cursor', null);
});

it('renders empty state when API returns zero loans', function () {
    Http::fake([
        '*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse(['summary' => [
            'n_loans' => 0, 'n_properties' => 0, 'n_companies' => 0, 'n_creditors' => 0,
            'total_principal_kr' => 0, 'avg_rate' => 0,
        ]])),
    ]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '9999')
        ->assertSee('Ingen lån matcher dine filtre');
});

it('renders error state on API failure', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response([], 500)]);

    Livewire::test(DebtSearch::class)
        ->set('postalCodeFrom', '2000')
        ->assertSet('error', 'Søgetjenesten er midlertidigt utilgængelig')
        ->assertSee('midlertidigt utilgængelig');
});

it('renders quota-exceeded state on 429', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(['error' => 'daily_quota_exceeded'], 429)]);

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
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse([
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

// Rasmus-feedback 2026-05-25: registered_from/registered_to date-range filter.

it('registeredFrom + registeredTo are sent as reg_from + reg_to to backend', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('registeredFrom', '2020-01-01')
        ->set('registeredTo', '2023-12-31');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/debt-search')
        && ($r->data()['registered_from'] ?? null) === '2020-01-01'
        && ($r->data()['registered_to'] ?? null) === '2023-12-31');
});

it('strips malformed date-input (partial typing) from API call', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('registeredFrom', '2020-1'); // user mid-typing

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/debt-search')
        && ! isset($r->data()['registered_from']));
});

it('mounts with reg_from query-param fires search (URL-bookmarkable filter)', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::withQueryParams(['reg_from' => '2024-01-01'])
        ->test(DebtSearch::class)
        ->assertSet('hasSearched', true)
        ->assertSet('registeredFrom', '2024-01-01');
});

it('resetFilters clears registeredFrom + registeredTo', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('registeredFrom', '2020-01-01')
        ->set('registeredTo', '2023-12-31')
        ->call('resetFilters')
        ->assertSet('registeredFrom', null)
        ->assertSet('registeredTo', null);
});

// --- Fri sortering, rammerente-markør, hide-nominal, eksport-teaser ---

it('sortBy toggles direction and sends sort to backend', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->call('sortBy', 'amount')
        ->assertSet('sort', 'amount_desc')
        ->call('sortBy', 'amount')
        ->assertSet('sort', 'amount_asc');

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/debt-search')
        && ($r->data()['sort'] ?? null) === 'amount_asc');
});

it('sortBy resets pagination (new keyset)', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('cursor', 'abc')
        ->set('cursorHistory', ['x', 'y'])
        ->call('sortBy', 'date')
        ->assertSet('cursor', null)
        ->assertSet('cursorHistory', []);
});

it('sortBy ignores unknown columns', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->call('sortBy', 'creditor')
        ->assertSet('sort', 'rate_desc'); // uændret
});

it('hideNominalRates sends hide_nominal_rates=1 to backend', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)->set('hideNominalRates', true);

    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/debt-search')
        && ($r->data()['hide_nominal_rates'] ?? null) === '1');
});

it('renders rammerente-badge on nominal-rate hits', function () {
    $resp = fakeDebtSearchResponse();
    $resp['results'][0]['debt']['is_nominal_rate'] = true;
    Http::fake(['*/v1/debt-search?*' => Http::response($resp)]);

    Livewire::test(DebtSearch::class)
        ->set('minRate', 8.0)
        ->assertSee('rammerente');
});

it('shows CSV export as a coming-soon teaser, not an active button', function () {
    Http::fake(['*/v1/debt-search?*' => Http::response(fakeDebtSearchResponse())]);

    Livewire::test(DebtSearch::class)
        ->set('minRate', 8.0)
        ->assertSee('kommer snart')
        ->assertDontSeeHtml('wire:click="downloadCsv"');
});

it('🚨 uden pilot-token: ingen filtre, ingen resultater, og API-et kaldes ikke, heller ikke ved direkte kald', function () {
    session()->forget('metis_user_token');
    Http::fake();

    Livewire::withQueryParams(['rate_min' => 9, 'rate_max' => 12])
        ->test(DebtSearch::class)
        ->assertSee('Kun for pilotbrugere')
        ->assertDontSee('Filtre')
        ->call('search')
        ->call('downloadCsv')
        ->call('nextPage')
        ->call('sortBy', 'rate')
        ->set('minRate', 10.0)
        ->assertSet('hasSearched', false);

    Http::assertNothingSent();
});

it('efter e-mail-bekræftelse på siden vises filtrene og søgningen kører uden genindlæsning', function () {
    session()->forget('metis_user_token');
    Http::fake(['*/v1/debt-search*' => Http::response(['data' => ['results' => [], 'summary' => []]])]);

    $c = Livewire::withQueryParams(['rate_min' => 9, 'rate_max' => 12])
        ->test(DebtSearch::class)
        ->assertSee('Kun for pilotbrugere')
        ->assertSet('hasSearched', false);

    // EmailGate hæfter tokenet og sender eventen; komponenten skal reagere.
    session(['metis_user_token' => '19|abc']);
    $c->dispatch('email-verified')
        ->assertDontSee('Kun for pilotbrugere')
        ->assertSee('Filtre')
        ->assertSet('hasSearched', true);
});

it('🚨 RegistryApi::debtSearch afviser selv uden pilot-token, uanset hvem der kalder', function () {
    session()->forget('metis_user_token');
    Http::fake();

    $api = app(\TheFountainhead\Metis\Services\RegistryApi::class);

    expect($api->debtSearch(['min_rate' => 9]))->toMatchArray(['error' => 'pilot_required', 'status' => 403])
        ->and($api->createDebtSearchCsvLink(['min_rate' => 9]))->toMatchArray(['error' => 'pilot_required', 'status' => 403]);

    Http::assertNothingSent();
});

it('er gaten slået fra (embedded bag værtens login), kræves intet token', function () {
    session()->forget('metis_user_token');
    config()->set('metis.gating.enabled', false);
    Http::fake(['*/v1/debt-search*' => Http::response(['data' => ['results' => [], 'summary' => []]])]);

    Livewire::withQueryParams(['rate_min' => 9, 'rate_max' => 12])
        ->test(DebtSearch::class)
        ->assertDontSee('Kun for pilotbrugere')
        ->assertSet('hasSearched', true);
});
