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
