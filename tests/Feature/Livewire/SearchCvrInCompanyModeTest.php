<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

it('routes 8-digit CVR to fetchCompany when searchMode=company (regression)', function () {
    // Stub registry-api endpoints. fetchCompany($cvr) hits /v1/cvr/{cvr},
    // searchByName($q) hits /v1/cvr/search?q=...
    Http::fake([
        '*/v1/cvr/company/28963610' => Http::response([
            'data' => ['company' => ['cvr' => '28963610', 'name' => 'Mimo Invest ApS']],
        ], 200),
        // Autocomplete (updatedQuery lifecycle) may also fire searchByName when
        // user-typing crosses 4-char threshold. That's separate from search() bug.
        '*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => []]], 200),
    ]);

    Livewire::test(Search::class)
        ->set('searchMode', 'company')
        ->set('query', '28963610')
        ->call('search')
        ->assertSet('error', false);

    // KEY assertion: fetchCompany was called for the CVR (the bug-fix).
    // Pre-fix, only searchByName was called → no match → "Ingen resultater".
    Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/cvr/company/28963610'));
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
