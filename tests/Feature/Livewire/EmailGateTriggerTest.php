<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Search;

beforeEach(function () {
    config(['metis.gating.enabled' => true, 'metis.gating.free_lookups' => 1]);
    Http::fake();
});

it('shows the email gate on the second lookup for anonymous users', function () {
    session(['metis_lookup_count' => 1, 'metis_lookup_window_start' => now()->timestamp]);

    Livewire::test(Search::class)
        ->set('searchMode', 'company')
        ->set('query', '28963610')
        ->call('search')
        ->assertDispatched('show-email-gate')
        ->assertNotDispatched('update-url');
});

it('bypasses the gate when a pilot token is active', function () {
    session(['metis_lookup_count' => 5, 'metis_user_token' => '2|abcDEF123', 'metis_lookup_window_start' => now()->timestamp]);

    Livewire::test(Search::class)
        ->set('searchMode', 'company')
        ->set('query', '28963610')
        ->call('search')
        ->assertNotDispatched('show-email-gate')
        ->assertSet('resultType', 'cvr');
});

it('lets verified emails through within the rate limit', function () {
    session(['metis_lookup_count' => 3, 'metis_verified_email' => 'test@frankston.io', 'metis_lookup_window_start' => now()->timestamp]);

    Livewire::test(Search::class)
        ->set('searchMode', 'company')
        ->set('query', '28963610')
        ->call('search')
        ->assertNotDispatched('show-email-gate')
        ->assertSet('resultType', 'cvr');
});

it('rate limits verified users past their hourly quota', function () {
    config(['metis.rate_limits.verified' => 10]);
    session(['metis_lookup_count' => 10, 'metis_verified_email' => 'test@frankston.io', 'metis_lookup_window_start' => now()->timestamp]);

    Livewire::test(Search::class)
        ->set('searchMode', 'company')
        ->set('query', '28963610')
        ->call('search')
        ->assertSet('rateLimited', true);
});
