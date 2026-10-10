<?php

use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\PersonSummary;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * registry-api leverer regnskabsbeløb i KRONER og har gjort det siden første
 * commit (26/2-2026). Metis' komponenter kom fra Frankston-master, hvor beløbene
 * blev delt med 100 som om de var øre. Rettelsen 31/3 (fa0964c) ramte kun
 * regnskabstabellen; personens formue, kapitaludvidelser og selskabskortet
 * var stadig 100× for små.
 */
function fakePersonCompanies(array $companies): void
{
    $api = Mockery::mock(RegistryApi::class)->makePartial();
    $api->shouldReceive('fetchCompaniesByCpr')->andReturn(['companies' => $companies]);
    $api->shouldReceive('fetchPropertiesByCpr')->andReturn(['properties' => []]);
    $api->shouldReceive('fetchCrossOwnership')->andReturn(['relationships' => []]);
    app()->instance(RegistryApi::class, $api);
}

function almaCompany(array $financials): array
{
    return [
        'cvr' => '44892723',
        'name' => 'Alma Madmarked A/S',
        'status' => 'NORMAL',
        'is_active' => true,
        'roles' => [['role' => 'legal_owner', 'is_current' => true, 'ownership_share' => 50]],
        'financials' => $financials,
    ];
}

it('regner personens andel af egenkapitalen i kroner, ikke øre', function () {
    fakePersonCompanies([almaCompany([['year' => '2025', 'equity' => 14_320_268]])]);

    Livewire::test(PersonSummary::class, ['query' => '0101011234'])
        ->assertSet('totalEquityShare', 7_160_134.0)
        ->assertSet('estimatedNetWorth', 7_160_134.0)
        ->assertSee('7,2M');
});

it('viser kapitaludvidelser i kroner, ikke øre', function () {
    fakePersonCompanies([almaCompany([
        ['year' => '2025', 'equity' => 30_000_000, 'profit_loss' => 0, 'contributed_capital' => 6_250_000],
        ['year' => '2024', 'equity' => 20_000_000, 'profit_loss' => 0, 'contributed_capital' => 5_000_000],
    ])]);

    Livewire::test(PersonSummary::class, ['query' => '0101011234'])
        ->assertSee('5.000.000 kr.')
        ->assertSee('6.250.000 kr.')
        ->assertSee('+1.250.000 kr.')
        ->assertSee('10.000.000 kr.')
        ->assertSee('50,0M kr.');
});

