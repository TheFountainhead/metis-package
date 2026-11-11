<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\WatchedCompanies;

function watchedRow(array $o = []): array
{
    return $o + ['watchlist_id' => 7, 'cvr' => '41867132', 'name' => 'URBAN GROUP ApS', 'status' => 'NORMAL', 'properties_direct' => 0, 'properties_group' => 97];
}

beforeEach(function () {
    Http::preventStrayRequests();
    config()->set('metis.gating.enabled', true);
    session()->start();
});

function watchedPilot(): void
{
    session(['metis_user_token' => '19|abc', 'metis_pilot_verificeret' => true]);
}

it('uden pilot vises "kun for pilotbrugere", aldrig en tom liste, og API-et kaldes ikke', function () {
    Http::fake();

    Livewire::test(WatchedCompanies::class)
        ->assertSee('Kun for pilotbrugere')
        ->assertDontSee('Du følger ingen');

    Http::assertNothingSent();
});

it('et token der ikke er en bekræftet pilot henter intet (load er offentlig over /livewire/update)', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake();

    Livewire::test(WatchedCompanies::class)->call('load')
        ->assertSet('rows', null)
        ->assertSee('kræver en bekræftet pilotbruger');

    Http::assertNothingSent();
});

it('viser de overvågede selskaber med link, status og ejendomme direkte og i koncernen', function () {
    watchedPilot();
    Http::fake(['*/v1/watchlists/companies' => Http::response(['data' => [
        watchedRow(),
        watchedRow(['watchlist_id' => 8, 'cvr' => '32676014', 'name' => 'THYBO EJENDOMSUDVIKLING A/S', 'status' => 'UNDER KONKURS', 'properties_direct' => 109, 'properties_group' => 143]),
    ]])]);

    Livewire::test(WatchedCompanies::class)
        ->assertSee('2 selskaber')
        ->assertSee('Urban Group ApS')
        ->assertSee('/lookup/cvr/41867132', false)
        ->assertSee('97')
        ->assertSee('UNDER KONKURS')
        ->assertSee('109')
        ->assertSee('143');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/watchlists/companies') && $r->hasHeader('Authorization', 'Bearer 19|abc'));
});

it('ingen overvågede selskaber er et gyldigt svar', function () {
    watchedPilot();
    Http::fake(['*/v1/watchlists/companies' => Http::response(['data' => []])]);

    Livewire::test(WatchedCompanies::class)->assertSee('Du følger ingen selskaber endnu');
});

it('fejl og svar uden data-liste vises som "kunne ikke hentes", ikke som en tom liste', function ($svar) {
    watchedPilot();
    Http::fake(['*/v1/watchlists/companies' => $svar]);

    Livewire::test(WatchedCompanies::class)
        ->assertSet('rows', null)
        ->assertSee('Kunne ikke hente de overvågede selskaber')
        ->assertDontSee('Du følger ingen');
})->with([
    '500' => fn () => Http::response('', 500),
    '200 uden data' => fn () => Http::response(['message' => 'ok']),
]);

it('selskab uden navn vises som ukendt med CVR', function () {
    watchedPilot();
    Http::fake(['*/v1/watchlists/companies' => Http::response(['data' => [watchedRow(['cvr' => '99999999', 'name' => null, 'status' => null, 'properties_group' => 0])]])]);

    Livewire::test(WatchedCompanies::class)->assertSee('Ukendt selskab')->assertSee('99999999');
});

it('ruten findes', function () {
    expect(route('metis.watched-companies'))->toEndWith('/overvaagede-selskaber');
});

it('API-metoden afviser uden bekræftet pilot og sender intet (gate i RegistryApi)', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake();

    expect(app(\TheFountainhead\Metis\Services\RegistryApi::class)->listWatchedCompanies())
        ->toMatchArray(['error' => 'pilot_required', 'status' => 403]);

    Http::assertNothingSent();
});

it('komponenten spørger slet ikke API-et uden bekræftet pilot (gate i load)', function () {
    session(['metis_user_token' => '19|abc']);
    $api = Mockery::mock(\TheFountainhead\Metis\Services\RegistryApi::class);
    $api->shouldNotReceive('listWatchedCompanies');
    app()->instance(\TheFountainhead\Metis\Services\RegistryApi::class, $api);

    Livewire::test(WatchedCompanies::class)->call('load')->assertSet('rows', null);
});
