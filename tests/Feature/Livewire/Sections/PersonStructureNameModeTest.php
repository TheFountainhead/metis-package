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

it('never asks for private properties', function () {
    // Håndhævet ved at UDELADE person-portfolio-mønstret + preventStrayRequests.
    // 🚨 Http::assertNotSent er INERT sammen med Http::pool — brug ikke den.
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    $test = Livewire::test(PersonStructure::class, ['query' => 'Test Person']);

    expect($test->get('layers'))->toBe(['ownership', 'roles']);
});

it('builds an ownership edge with a percentage label from the name payload', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person'])
        ->assertSet('skeletonStatus', 'loaded')
        ->assertSee('Test Holding ApS');
});

it('shows the empty state immediately when the person has no active companies', function () {
    // 'empty' er settled fra mount — ingen shimmer, ingen poll-vent.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person'])
        ->assertSet('skeletonStatus', 'empty')
        ->assertSee('Ingen aktive selskabsrelationer')
        ->assertDontSeeHtml('wire:poll');
});

it('sets failed, not empty, when the fetch fails', function () {
    Http::fake(['*person-companies-by-name*' => Http::failedConnection('cURL error 28')]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person'])
        ->assertSet('skeletonStatus', 'failed');
});

it('shows the empty state and the cpr note, not the failed message, when the name has no CVR hits', function () {
    // 404 means "genuinely not in CVR", not "could not be fetched" — asks
    // what the USER sees through the whole component, not just what the
    // client method returns, which is the gap that let this bug ship.
    Http::fake(['*person-companies-by-name*' => Http::response(['error' => 'Person not found'], 404)]);

    Livewire::test(PersonStructure::class, ['query' => 'Ukendt Person'])
        ->assertSet('skeletonStatus', 'empty')
        ->assertSee('Ingen aktive selskabsrelationer')
        ->assertSee('private ejendomme vises ikke')
        ->assertDontSee('Selskabsrelationerne kunne ikke hentes.');
});

it('shows the cpr note in name mode', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person'])
        ->assertSee('private ejendomme vises ikke');
});

it('shows the cpr note even when there are no companies', function () {
    // Særligt vigtigt her: personen KAN have private ejendomme vi ikke kan se.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person'])
        ->assertSee('private ejendomme vises ikke');
});

it('does not render the private properties chip in name mode', function () {
    Http::fake(['*person-companies-by-name*' => Http::response(nameModeCompaniesPayload())]);

    Livewire::test(PersonStructure::class, ['query' => 'Test Person'])
        ->assertDontSee('Private ejendomme');
});
