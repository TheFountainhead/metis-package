<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\PersonFollowButton;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

function fakeDisambiguateResponse(array $candidates): array
{
    return [
        'data' => [
            'candidates' => $candidates,
            'total' => count($candidates),
        ],
    ];
}

function candidate(string $enhedsnummer, string $name, ?string $city = null, array $companies = []): array
{
    return [
        'enhedsnummer' => $enhedsnummer,
        'name' => $name,
        'address' => $city ? ['street' => 'Hovedgade', 'number' => '1', 'postal_code' => '2100', 'city' => $city] : null,
        'companies' => $companies,
    ];
}

it('mounts with name and starts closed', function () {
    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->assertSet('name', 'Peter Nielsen')
        ->assertSet('open', false)
        ->assertSet('candidates', []);
});

it('openModal fetches candidates and opens', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København'),
            candidate('4001000002', 'Peter Nielsen', 'Aarhus'),
        ])),
        '*check-batch*' => Http::response(['data' => []]),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->assertSet('open', true)
        ->assertCount('candidates', 2)
        ->assertSee('Peter Nielsen')
        ->assertSee('København')
        ->assertSee('Aarhus');
});

it('closeModal hides the modal but keeps candidates cached', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København'),
        ])),
        '*check-batch*' => Http::response(['data' => []]),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->call('closeModal')
        ->assertSet('open', false)
        ->assertCount('candidates', 1);
});

it('renders error when disambiguation API fails', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(['error' => 'boom'], 500),
        '*check-batch*' => Http::response(['data' => []]),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->assertSee('Kunne ikke hente person-kandidater');
});

it('shows empty-state when CVR returns 0 candidates', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([])),
        '*check-batch*' => Http::response(['data' => []]),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Asger Saksiformig'])
        ->call('openModal')
        ->assertSee('Ingen kandidater fundet');
});

it('follow creates watchlist with watch_type=person and enhedsnummer', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København', [
                ['cvr' => '11111111', 'name' => 'Acme A/S', 'roles' => [['role' => 'Direktør', 'is_current' => true]]],
            ]),
        ])),
        '*check-batch*' => Http::response(['data' => []]),
        '*watchlists' => Http::response(['data' => ['id' => 42]], 201),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->call('follow', '4001000001')
        ->assertSet('followState.4001000001.is_followed', true)
        ->assertSet('followState.4001000001.watchlist_id', 42);

    Http::assertSent(function ($request) {
        $url = (string) $request->url();
        $data = $request->data();

        return str_contains($url, '/v1/watchlists')
            && $request->method() === 'POST'
            && ($data['watch_type'] ?? '') === 'person'
            && ($data['watch_value'] ?? '') === '4001000001';
    });
});

it('follow sends display_label combining name + city + top-company', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København', [
                ['cvr' => '11111111', 'name' => 'Acme A/S', 'roles' => [['role' => 'Direktør', 'is_current' => true]]],
            ]),
        ])),
        '*check-batch*' => Http::response(['data' => []]),
        '*watchlists' => Http::response(['data' => ['id' => 42]], 201),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->call('follow', '4001000001');

    Http::assertSent(function ($request) {
        if (! str_contains((string) $request->url(), '/v1/watchlists') || $request->method() !== 'POST') {
            return false;
        }
        $label = $request->data()['display_label'] ?? '';

        return str_contains($label, 'Peter Nielsen')
            && str_contains($label, 'København')
            && str_contains($label, 'Acme A/S');
    });
});

it('follow shows error on API failure', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København'),
        ])),
        '*check-batch*' => Http::response(['data' => []]),
        '*watchlists' => Http::response(['error' => 'opted_out'], 422),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->call('follow', '4001000001')
        ->assertSee('Kunne ikke følge')
        ->assertSet('followState.4001000001', null);
});

it('unfollow deletes watchlist and resets state', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København'),
        ])),
        '*check-batch*' => Http::response(['data' => [
            ['type' => 'person', 'value' => '4001000001', 'is_followed' => true, 'watchlist_id' => 42],
        ]]),
        '*watchlists/42' => Http::response(['data' => true]),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->assertSet('followState.4001000001.is_followed', true)
        ->call('unfollow', '4001000001')
        ->assertSet('followState.4001000001.is_followed', false)
        ->assertSet('followState.4001000001.watchlist_id', null);
});

it('refreshFollowState populates from checkBatch on modal open', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København'),
            candidate('4001000002', 'Peter Nielsen', 'Aarhus'),
        ])),
        '*check-batch*' => Http::response(['data' => [
            ['type' => 'person', 'value' => '4001000001', 'is_followed' => true, 'watchlist_id' => 42],
            ['type' => 'person', 'value' => '4001000002', 'is_followed' => false, 'watchlist_id' => null],
        ]]),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->assertSet('followState.4001000001.is_followed', true)
        ->assertSet('followState.4001000001.watchlist_id', 42)
        ->assertSet('followState.4001000002.is_followed', false);
});

it('does not re-fetch candidates on second openModal call but re-syncs follow-state', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København'),
        ])),
        '*check-batch*' => Http::response(['data' => []]),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->call('closeModal')
        ->call('openModal');

    // 3 = 1× disambiguate (cached) + 2× check-batch (re-synced on reopen).
    Http::assertSentCount(3);
    expect(Http::recorded(fn ($request) => str_contains($request->url(), 'person-disambiguate')))
        ->toHaveCount(1);
});

it('follow is a no-op when candidate is already followed (double-click guard)', function () {
    Http::fake([
        '*person-disambiguate*' => Http::response(fakeDisambiguateResponse([
            candidate('4001000001', 'Peter Nielsen', 'København'),
        ])),
        '*check-batch*' => Http::response(['data' => [
            ['type' => 'person', 'value' => '4001000001', 'is_followed' => true, 'watchlist_id' => 42],
        ]]),
    ]);

    Livewire::test(PersonFollowButton::class, ['name' => 'Peter Nielsen'])
        ->call('openModal')
        ->call('follow', '4001000001')
        ->assertSet('followState.4001000001.is_followed', true)
        ->assertSet('followState.4001000001.watchlist_id', 42);

    // The second click must NOT create a duplicate watchlist. createWatchlist
    // posts to /v1/watchlists; check-batch (also POST) must not count.
    Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/v1/watchlists')
        && $request->method() === 'POST');
});
