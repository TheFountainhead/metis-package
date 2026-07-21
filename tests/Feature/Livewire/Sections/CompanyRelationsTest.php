<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyRelations;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

function fakeRelations(array $payload): void
{
    Http::fake(['*/v1/cvr/company-relations' => Http::response(['data' => $payload])]);
}

it('viser aktieposter begge retninger, mærket som ikke-koncern', function () {
    fakeRelations([
        'outgoing' => [['cvr' => '20000002', 'name' => 'DatterAktie ApS', 'property_count' => 15, 'property_value_kr' => 30_726_000]],
        'incoming' => [['cvr' => '30000003', 'name' => 'Moder-aktionær A/S']],
        'outgoing_count' => 1,
        'incoming_count' => 1,
    ]);

    Livewire::test(CompanyRelations::class, ['query' => '10000001'])
        ->assertSee('Aktieposter & relationer')
        ->assertSee('ikke bekræftet koncern-ejerskab')
        ->assertSee('Aktionær i')
        ->assertSee('DatterAktie ApS')
        ->assertSee('Aktionærer')
        ->assertSee('Moder-aktionær A/S')
        ->assertSee('15 ejendomme')
        ->assertSee('30.726.000 kr.');
});

it('skjules helt når ingen aktieposter', function () {
    fakeRelations(['outgoing' => [], 'incoming' => [], 'outgoing_count' => 0, 'incoming_count' => 0]);

    Livewire::test(CompanyRelations::class, ['query' => '10000001'])
        ->assertDontSee('Aktieposter & relationer');
});
