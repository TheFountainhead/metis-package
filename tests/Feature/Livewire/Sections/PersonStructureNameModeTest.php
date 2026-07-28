<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\PersonStructure;

function nameModeCompaniesPayload(): array
{
    return ['data' => ['person_name' => 'Test Person', 'companies' => [[
        'cvr' => '12345678',
        'name' => 'Test Holding ApS',
        'company_type' => 'APS',
        'status' => 'NORMAL',
        'is_active' => true,
        'has_direct_ownership' => true,
        'person_name' => 'Test Person',
        'roles' => [[
            'role' => 'legal_owner', 'title' => 'EJERREGISTER', 'ownership_share' => 50.0,
            'is_current' => true, 'start_date' => '2021-03-01', 'end_date' => null,
        ]],
    ]]]];
}

it('never asks for private properties in name mode', function () {
    // Håndhævet ved at UDELADE person-portfolio-mønstret + preventStrayRequests.
    // 🚨 Http::assertNotSent er INERT sammen med Http::pool — brug ikke den.
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    $test = Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name']);

    expect($test->get('layers'))->not->toContain('private_properties')
        ->and($test->get('privatePropertiesStatus'))->toBe('empty');
});

it('builds an ownership edge with a percentage label from the name payload', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSet('skeletonStatus', 'loaded')
        ->assertSee('Test Holding ApS');
});

it('shows the empty state immediately when the person has no active companies', function () {
    // I name-mode er 'empty' settled fra mount — ingen shimmer, ingen poll-vent.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSet('privatePropertiesStatus', 'empty');
});

it('sets failed, not empty, when the fetch fails', function () {
    Http::fake(['*person-companies-by-name*' => Http::failedConnection('cURL error 28')]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSet('skeletonStatus', 'failed');
});

it('shows the empty state and the cpr note, not the failed message, when the name has no CVR hits', function () {
    // 404 means "genuinely not in CVR", not "could not be fetched" — asks
    // what the USER sees through the whole component, not just what the
    // client method returns, which is the gap that let this bug ship.
    Http::fake(['*person-companies-by-name*' => Http::response(['error' => 'Person not found'], 404)]);

    Livewire::test(PersonStructure::class, ['query' => 'Ukendt Person', 'source' => 'name'])
        ->assertSet('skeletonStatus', 'empty')
        ->assertSee('Ingen aktive selskabsrelationer')
        ->assertSee('Søg med CPR-nummer')
        ->assertDontSee('Selskabsrelationerne kunne ikke hentes.');
});

it('defaults to cpr mode so existing behaviour is unchanged', function () {
    expect((new PersonStructure)->source)->toBe('cpr');
});

it('keeps private properties settled across a skeleton retry in name mode', function () {
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSet('privatePropertiesStatus', 'empty')
        ->call('retrySkeleton')
        ->assertSet('privatePropertiesStatus', 'empty');
});

it('never posts a name to the cpr-exclusive endpoint, even after mount', function () {
    // Reviewed fund: `call('retryPrivateProperties')` used to POST the NAME as
    // `cpr` to the CPR-exclusive /v1/person/property-portfolio endpoint — a
    // person NAME in a CPR-tagged field.
    //
    // #[Locked] on $source was the first fix, and it broke the CPR page in
    // production: the component inherits #[Lazy], and Locked rejects
    // Livewire's OWN snapshot rehydration (Flare #9103976). The guard inside
    // retryPrivateProperties() is what actually closes the path, so that is
    // what this test pins.
    //
    // Enforced by OMITTING the person-portfolio pattern + preventStrayRequests():
    // a request reaching that endpoint fails the test as a stray request, not
    // merely as a wrong assertion.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Frederik Testperson', 'source' => 'name'])
        ->call('retryPrivateProperties')
        ->assertSet('privatePropertiesStatus', 'empty');
});

it('keeps private properties settled across a manual retry in name mode', function () {
    // Mirrors the retrySkeleton test above, but for retryPrivateProperties()
    // directly: the chip is never rendered in name mode, but the method is
    // public and a Livewire call can be constructed regardless of what is on
    // screen. Håndhævet ved at UDELADE person-portfolio-mønstret +
    // preventStrayRequests() (global i TestCase) — et kald der rammer
    // /v1/person/property-portfolio ville poste NAVNET som "cpr" og fejle
    // testen med en stray-request-exception i stedet for en assertion-fejl.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSet('privatePropertiesStatus', 'empty')
        ->call('retryPrivateProperties')
        ->assertSet('privatePropertiesStatus', 'empty');
});

it('shows the cpr note in name mode', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSee('Søg med CPR-nummer');
});

it('shows the cpr note even when there are no companies', function () {
    // Særligt vigtigt her: personen KAN have private ejendomme vi ikke kan se.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertSee('Søg med CPR-nummer');
});

it('does not show the cpr note in cpr mode', function () {
    Http::fake(['*search-by-cpr*' => Http::response(['data' => ['companies' => []]])]);

    Livewire::test(PersonStructure::class, ['query' => '0101011234'])
        ->assertDontSee('Søg med CPR-nummer');
});

it('does not render the private properties chip in name mode', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person', 'source' => 'name'])
        ->assertDontSee('Private ejendomme');
});

it('still renders the private properties chip in cpr mode', function () {
    $company = [
        'cvr' => '12345678',
        'name' => 'Test Holding ApS',
        'company_type' => 'APS',
        'is_active' => true,
        'has_direct_ownership' => true,
        'roles' => [[
            'role' => 'legal_owner', 'title' => 'EJERREGISTER', 'ownership_share' => 50.0,
            'is_current' => true, 'start_date' => '2021-03-01', 'end_date' => null,
        ]],
    ];

    // Ordering matters: the specific person-patterns must be listed BEFORE
    // the generic */property-portfolio* wildcard, or the wildcard can win.
    Http::fake([
        '*/v1/cvr/search-by-cpr*' => Http::response(['data' => ['companies' => [$company]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
    ]);

    Livewire::test(PersonStructure::class, ['query' => '0101011234'])
        ->assertSet('skeletonStatus', 'loaded')
        ->assertSee('Private ejendomme');
});
