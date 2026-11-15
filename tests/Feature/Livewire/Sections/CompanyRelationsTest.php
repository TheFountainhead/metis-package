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

it('viser stiftere begge retninger og siger, at det ikke er ejerskab', function () {
    // Rollen bag listen (shareholder) skrives KUN fra CVR's organisation "Stiftere"
    // (registry-api CvrService::mapRoleType). Overskrifterne sagde "Aktionær i"/"Aktionærer".
    fakeRelations([
        'outgoing' => [['cvr' => '20000002', 'name' => 'Stiftet ApS', 'property_count' => 15, 'property_value_kr' => 30_726_000]],
        'incoming' => [['cvr' => '30000003', 'name' => 'Stifter A/S']],
        'outgoing_count' => 1,
        'incoming_count' => 1,
    ]);

    Livewire::test(CompanyRelations::class, ['query' => '10000001'])
        ->assertSee('Stiftere og stiftede selskaber')
        ->assertSee('Viser ikke nuværende ejerskab')
        ->assertSee('Personer vises under Ledelse & roller')
        ->assertSeeInOrder(['Stifter af', 'Stiftet ApS', 'Stiftet af', 'Stifter A/S'])
        ->assertDontSee('Aktionær')
        ->assertDontSee('Aktieposter')
        ->assertSee('15 ejendomme')
        ->assertSee('30.726.000 kr.');
});

it('skjules helt når ingen stiftere', function () {
    fakeRelations(['outgoing' => [], 'incoming' => [], 'outgoing_count' => 0, 'incoming_count' => 0]);

    Livewire::test(CompanyRelations::class, ['query' => '10000001'])
        ->assertDontSee('Stiftere og stiftede selskaber');
});
