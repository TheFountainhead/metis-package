<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

it('upgrades type=company_name to type=cvr when input is 8-digit (regression)', function () {
    Http::fake([
        '*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => []]], 200),
    ]);

    // CVR-flow: search() detects 8-digit → upgrades type to 'cvr' → inline-render
    // path fires (resultType=cvr) + update-url dispatched → returns early before
    // performSearch. Actual CVR-data is fetched by /lookup/cvr/{cvr} sections.
    Livewire::test(Search::class)
        ->set('searchMode', 'company')
        ->set('query', '28963610')
        ->call('search')
        ->assertSet('resultType', 'cvr')
        ->assertSet('error', false)
        ->assertDispatched('update-url', query: '28963610', type: 'cvr');
});

it('routes non-CVR query to searchByName when searchMode=company', function () {
    Http::fake([
        '*/v1/cvr/search-by-name' => Http::response([
            'data' => ['companies' => [['cvr' => '12345678', 'name' => 'Mimo Holding ApS']]],
        ], 200),
        '*/v1/cvr/company/*' => Http::response(['data' => []], 404), // should NOT be hit for non-CVR
    ]);

    Livewire::test(Search::class)
        ->set('searchMode', 'company')
        ->set('query', 'Mimo')
        ->call('search')
        ->assertSet('error', false);

    Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/cvr/search-by-name'));
});
