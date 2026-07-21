<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyProperties;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)
            ->name('metis.lookup')
            ->where('query', '.*');
    }
});

function fakeCompanyPropertiesResponse(array $portfolio): void
{
    Http::fake([
        '*enrichment/*status*' => Http::response(['data' => ['status' => 'done', 'properties_found' => 0]]),
        '*property-portfolio*' => Http::response(['data' => ['portfolio' => $portfolio]]),
    ]);
}

it('viser ejer-kolonne + vurderings-dæknings-note på koncern-stien', function () {
    fakeCompanyPropertiesResponse([
        'owner_type' => 'company',
        'owner_name' => 'JEUDAN A/S',
        'owner_cvr' => '14246045',
        'source' => 'koncern_bfe',
        'total_count' => 2,
        'property_count' => 2,
        'total_valuation' => 50_000_000,
        'valuation_coverage' => ['with_valuation' => 1, 'total' => 2],
        'properties' => [
            ['matrikel_id' => '123456', 'address' => 'Bredgade 40', 'postal_code' => '1260', 'city' => 'København K',
             'owner_name' => 'JEUDAN A/S', 'owner_cvr' => '14246045', 'valuation' => 50_000_000, 'total_debt' => 10_000_000],
            ['matrikel_id' => '654321', 'address' => 'Amaliegade 4', 'postal_code' => '1256', 'city' => 'København K',
             'owner_name' => 'Datter ApS', 'owner_cvr' => '22222222', 'valuation' => null, 'total_debt' => 5_000_000],
        ],
    ]);

    Livewire::test(CompanyProperties::class, ['query' => '14246045'])
        ->assertSee('Ejer')                     // ejer-kolonne-header (koncern-krav)
        ->assertSee('Datter ApS')               // datterselskabets navn pr. ejendom
        ->assertSee('vurdering for 1/2');       // dæknings-note (undertal ærligt)
});

it('viser IKKE ejer-kolonne når kilden er EJF (ingen source)', function () {
    fakeCompanyPropertiesResponse([
        'owner_type' => 'company',
        'owner_name' => 'Enkelt ApS',
        'owner_cvr' => '11111111',
        // ingen 'source' → EJF-sti
        'total_count' => 1,
        'property_count' => 1,
        'total_valuation' => 20_000_000,
        'properties' => [
            ['matrikel_id' => '999888', 'address' => 'Testvej 1', 'postal_code' => '2100', 'city' => 'København Ø',
             'valuation' => 20_000_000, 'total_debt' => 0],
        ],
    ]);

    Livewire::test(CompanyProperties::class, ['query' => '11111111'])
        ->assertSee('Testvej 1')                                          // ejendommen renders normalt
        ->assertDontSee('vurdering for')                                  // ingen coverage-note uden valuation_coverage
        ->assertDontSee('Hvilket koncern-selskab der ejer ejendommen');   // ejer-kolonne-header fraværende (EJF uændret)
});
