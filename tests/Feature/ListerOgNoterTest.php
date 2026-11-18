<?php

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\ListButton;
use TheFountainhead\Metis\Livewire\Lists;
use TheFountainhead\Metis\Livewire\Notes;

/**
 * Brugerens egne lister og noter (registry-api /v1/lists og /v1/notes).
 * Hovedreglen: kun med brugerens EGET token. Uden det kaldes API'et aldrig (ellers ville den fælles nøgle blive brugt).
 */
beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    session()->start();
});

function listePilot(): void
{
    session(['metis_user_token' => '19|abc', 'metis_pilot_verificeret' => true]);
}

function listeSvar(array $lister): array
{
    return ['data' => $lister];
}

it('🚨 uden eget token kalder hverken knap, noter eller siden API-et, heller ikke via de offentlige metoder', function () {
    Http::fake();

    Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')->call('skift', 1)->set('nytNavn', 'X')->call('opret')->assertSet('lister', null);
    Livewire::test(Notes::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')->set('tekst', 'x')->call('gem')->call('slet', 1)->call('ret', 1)->call('gemRettelse')->assertSet('noter', null);
    Livewire::test(Lists::class)->call('hent')->set('nytNavn', 'X')->call('opret')->assertSee('Kun for pilotbrugere')->assertDontSee('Du har ingen lister');
    Livewire::test(Lists::class, ['id' => 5])->call('hent')->call('fjern', 1)->call('omdoeb')->call('sletListe')->assertSee('Kun for pilotbrugere');

    Http::assertNothingSent();
});

it('🚨 et token der ikke er en bekræftet pilot er ikke nok', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake();

    Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent');
    Livewire::test(Notes::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent');

    Http::assertNothingSent();
});

it('🚨 uden gating og uden eget token bruges den fælles nøgle aldrig til lister', function () {
    config()->set('metis.gating.enabled', false);
    Http::fake();

    Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent');
    Livewire::test(Lists::class)->call('hent');

    Http::assertNothingSent();
});

it('viser brugerens lister med flueben og tæller dem, selskabet står på', function () {
    listePilot();
    Http::fake(['*/v1/lists?*' => Http::response(listeSvar([
        ['id' => 1, 'name' => 'Ejendomme vi har pant i', 'items_count' => 3, 'contains' => true],
        ['id' => 2, 'name' => 'Selvskyldner check', 'items_count' => 0, 'contains' => false],
    ]))]);

    Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')
        ->assertSee('Ejendomme vi har pant i')->assertSee('Selvskyldner check')
        ->assertSeeHtml('data-liste-antal')->assertSee('(1)');

    Http::assertSent(fn (Request $r) => str_contains($r->url(), 'item_type=company') && str_contains($r->url(), 'item_value=12345678') && $r->hasHeader('Authorization', 'Bearer 19|abc'));
});

it('sætter selskabet på en liste med navnet fra sidens eget opslag som etiket', function () {
    listePilot();
    $api = app(\TheFountainhead\Metis\Services\RegistryApi::class);
    \Illuminate\Support\Facades\Cache::put((fn () => $this->companyInfoCacheKey('12345678'))->call($api), ['name' => 'Acme ApS'], 60);
    Http::fake([
        '*/v1/lists/2/items' => Http::response(['data' => ['id' => 9]], 201),
        '*/v1/lists?*' => Http::sequence()
            ->push(listeSvar([['id' => 2, 'name' => 'Selvskyldner check', 'contains' => false]]))
            ->push(listeSvar([['id' => 2, 'name' => 'Selvskyldner check', 'contains' => true]])),
    ]);

    Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')->call('skift', 2)
        ->assertSet('fejl', null)->assertSet('lister.0.contains', true);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/lists/2/items')
        && $r->data() === ['item_type' => 'company', 'item_value' => '12345678', 'display_label' => 'Acme ApS']);
});

it('tager selskabet af en liste, det står på', function () {
    listePilot();
    Http::fake([
        '*/v1/lists/2/items/77' => Http::response(null, 204),
        '*/v1/lists/2' => Http::response(['data' => ['id' => 2, 'name' => 'A', 'items' => [
            ['id' => 76, 'item_type' => 'property', 'item_value' => '12345678'],
            ['id' => 77, 'item_type' => 'company', 'item_value' => '12345678'],
        ]]]),
        '*/v1/lists?*' => Http::sequence()->push(listeSvar([['id' => 2, 'name' => 'A', 'contains' => true]]))->push(listeSvar([['id' => 2, 'name' => 'A', 'contains' => false]])),
    ]);

    Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')->call('skift', 2)->assertSet('lister.0.contains', false);

    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v1/lists/2/items/77'));
    Http::assertNotSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/items/76'));
});

it('🚨 klienten kan ikke pege på en liste, den ikke har fået vist', function () {
    listePilot();
    Http::fake(['*/v1/lists?*' => Http::response(listeSvar([['id' => 2, 'name' => 'A', 'contains' => false]]))]);

    $c = Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')->call('skift', 999)->assertSet('fejl', 'Listen findes ikke længere.');
    // Heller ikke ved at skrive id'et ind i komponentens tilstand: listerne er låst.
    expect(fn () => $c->set('lister', [['id' => 999, 'name' => 'x', 'contains' => false]]))->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

it('🚨 uden et navn i sidens opslag gemmes CVR-nummeret, aldrig fri tekst fra adressen i browseren', function () {
    listePilot();
    Http::fake([
        '*/v1/lists/2/items' => Http::response(['data' => ['id' => 9]], 201),
        '*/v1/lists?*' => Http::response(listeSvar([['id' => 2, 'name' => 'A', 'contains' => false]])),
    ]);

    Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => 'Jens Jensen 12 34 56 78'])->call('hent')->call('skift', 2);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['item_type' => 'company', 'item_value' => '12345678', 'display_label' => '12345678']);
});

it('opretter en ny liste og sætter siden på den, og viser API-ets besked når navnet er optaget', function () {
    listePilot();
    Http::fake([
        '*/v1/lists/5/items' => Http::response(['data' => ['id' => 1]], 201),
        '*/v1/lists?*' => Http::response(listeSvar([['id' => 5, 'name' => 'Ny', 'contains' => true]])),
        '*/v1/lists' => Http::sequence()->push(['data' => ['id' => 5, 'name' => 'Ny']], 201)->push(['errors' => ['name' => ['Du har allerede en liste med det navn.']]], 422),
    ]);

    $c = Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->set('nytNavn', '  Ny  ')->call('opret')
        ->assertSet('nytNavn', '')->assertSet('lister.0.id', 5);
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/lists') && $r->data() === ['name' => 'Ny']);

    $c->set('nytNavn', 'Ny')->call('opret')->assertSet('fejl', 'Du har allerede en liste med det navn.');
});

it('🚨 en fejl ligner ikke "ingen lister"', function () {
    listePilot();
    Http::fake(['*' => Http::sequence()->push([], 500)->push('ikke json')]);

    Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')
        ->assertSet('lister', null)->assertSee('Kunne ikke hente dine lister')->assertDontSee('Du har ingen lister');
    Livewire::test(Lists::class)->assertSet('lister', null)->assertSee('Kunne ikke hente dine lister')->assertDontSee('Du har ingen lister endnu');
});

it('en ejendom gemmes på sit matrikel-id med adressen som etiket', function () {
    listePilot();
    Http::fake([
        '*/v1/property/analysis*' => Http::response(['data' => ['property' => ['matrikel_id' => '2000174-12a', 'mortgages' => []]]]),
        '*/v1/lists/2/items' => Http::response(['data' => ['id' => 9]], 201),
        '*/v1/lists?*' => Http::response(listeSvar([['id' => 2, 'name' => 'A', 'contains' => false]])),
    ]);

    Livewire::test(ListButton::class, ['type' => 'address', 'query' => 'Testvej 1, 2100 København Ø'])->call('hent')->call('skift', 2);

    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && str_ends_with($r->url(), '/v1/lists/2/items')
        && $r->data() === ['item_type' => 'property', 'item_value' => '2000174-12a', 'display_label' => 'Testvej 1, 2100 København Ø']);
});

it('🚨 personer og andre sidetyper kan ikke sættes på en liste eller få noter', function (string $type, string $query) {
    listePilot();
    Http::fake();

    Livewire::test(ListButton::class, ['type' => $type, 'query' => $query])->call('hent')->set('nytNavn', 'X')->call('opret')->assertSet('lister', null);
    Livewire::test(Notes::class, ['type' => $type, 'query' => $query])->call('hent')->set('tekst', 'x')->call('gem')->assertSet('noter', null);

    Http::assertNothingSent();
})->with([
    'person' => ['person', '4000123456'],
    'navn' => ['name', 'Jens Jensen'],
    'cvr med 7 cifre' => ['cvr', '1234567'],
]);

it('viser, skriver, retter og sletter noter', function () {
    listePilot();
    $note = fn (int $id, string $tekst) => ['id' => $id, 'body' => $tekst, 'created_at' => '2026-11-18T10:00:00+00:00'];
    Http::fake([
        '*/v1/notes/4' => Http::response(['data' => $note(4, 'Rettet')]),
        '*/v1/notes/5' => Http::response(null, 204),
        '*/v1/notes?*' => Http::sequence()
            ->push(['data' => [$note(4, "Første linje\nAnden linje")]])
            ->push(['data' => [$note(5, 'Opfølgning torsdag'), $note(4, 'Første')]])
            ->push(['data' => [$note(5, 'Opfølgning torsdag'), $note(4, 'Rettet')]])
            ->push(['data' => [$note(4, 'Rettet')]]),
        '*/v1/notes' => Http::response(['data' => $note(5, 'Opfølgning torsdag')], 201),
    ]);

    $c = Livewire::test(Notes::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')
        ->assertSee('Første linje')->assertSee('18.11.2026 11:00');

    $c->set('tekst', '  Opfølgning torsdag  ')->call('gem')->assertSet('tekst', '')->assertSee('Opfølgning torsdag');
    Http::assertSent(fn (Request $r) => $r->method() === 'POST' && $r->data() === ['item_type' => 'company', 'item_value' => '12345678', 'body' => 'Opfølgning torsdag']);

    $c->call('ret', 4)->assertSet('retterTekst', 'Første')->set('retterTekst', 'Rettet')->call('gemRettelse')->assertSet('retterId', null)->assertSee('Rettet');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && str_ends_with($r->url(), '/v1/notes/4') && $r->data() === ['body' => 'Rettet']);

    $c->call('slet', 5)->assertDontSee('Opfølgning torsdag');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v1/notes/5'));
});

it('🚨 en note brugeren ikke har fået vist her, kan hverken rettes eller slettes herfra', function () {
    listePilot();
    Http::fake(['*/v1/notes?*' => Http::response(['data' => [['id' => 4, 'body' => 'Min']]])]);

    Livewire::test(Notes::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')
        ->call('slet', 999)->call('ret', 999)->assertSet('retterId', null)
        ->set('retterTekst', 'x')->call('gemRettelse');
    // Heller ikke ved at skrive id'et ind i komponentens tilstand: noterne og den note der rettes er låst.
    $c = Livewire::test(Notes::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent');
    expect(fn () => $c->set('noter', [['id' => 999, 'body' => '', 'created_at' => null]]))->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);
    expect(fn () => $c->set('retterId', 999))->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

    Http::assertNotSent(fn (Request $r) => $r->method() !== 'GET');
});

it('🚨 notens tekst vises som tekst, aldrig som HTML, og en for lang note sendes ikke', function () {
    listePilot();
    Http::fake(['*/v1/notes?*' => Http::response(['data' => [['id' => 4, 'body' => '<script>alert(1)</script><b>fed</b>']]])]);

    Livewire::test(Notes::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')
        ->assertDontSeeHtml('<script>alert(1)</script>')->assertSeeHtml('&lt;script&gt;')
        ->set('tekst', str_repeat('a', Notes::MAKS_TEGN + 1))->call('gem')->assertSee('Noten er for lang');

    Http::assertNotSent(fn (Request $r) => $r->method() === 'POST');
});

it('siden viser listerne med antal og kan oprette en', function () {
    listePilot();
    Http::fake([
        '*/v1/lists' => Http::sequence()
            ->push(listeSvar([['id' => 1, 'name' => 'Pant', 'items_count' => 1], ['id' => 2, 'name' => 'Tom', 'items_count' => 0]]))
            ->push(['data' => ['id' => 3, 'name' => 'Ny']], 201)
            ->push(listeSvar([['id' => 3, 'name' => 'Ny', 'items_count' => 0]])),
    ]);

    Livewire::test(Lists::class)->assertSee('Pant')->assertSee('1 element')->assertSee('0 elementer')
        ->assertSeeHtml('/lister/1')
        ->set('nytNavn', 'Ny')->call('opret')->assertSet('nytNavn', '')->assertSee('Ny');
});

it('en liste viser sine elementer med link, og kan fjerne ét, omdøbes og slettes', function () {
    listePilot();
    $liste = fn (string $navn, array $items) => ['data' => ['id' => 7, 'name' => $navn, 'items' => $items]];
    $selskab = ['id' => 1, 'item_type' => 'company', 'item_value' => '12345678', 'display_label' => 'Acme ApS'];
    $ejendom = ['id' => 2, 'item_type' => 'property', 'item_value' => '2000174-12a', 'display_label' => 'Testvej 1, 2100 København Ø'];
    $udenAdresse = ['id' => 3, 'item_type' => 'property', 'item_value' => '99-1b', 'display_label' => null];
    Http::fake([
        '*/v1/lists/7/items/2' => Http::response(null, 204),
        '*/v1/lists/7' => Http::sequence()
            ->push($liste('Pant', [$selskab, $ejendom, $udenAdresse]))
            ->push($liste('Pant', [$selskab, $udenAdresse]))
            ->push($liste('Nyt navn', [$selskab, $udenAdresse]))   // svar på PATCH
            ->push($liste('Nyt navn', [$selskab, $udenAdresse]))
            ->push(null, 204),                                     // svar på DELETE
    ]);

    $c = Livewire::test(Lists::class, ['id' => 7])
        ->assertSee('Acme ApS')->assertSeeHtml('/lookup/cvr/12345678')
        ->assertSee('Testvej 1, 2100 København Ø')->assertSeeHtml('/lookup/address/')
        ->assertSee('99-1b'); // uden adresse: vist, men uden link
    expect(substr_count($c->html(), 'data-liste-element'))->toBe(3)
        ->and(substr_count($c->html(), '/lookup/address/'))->toBe(1);

    $c->call('fjern', 2)->assertDontSee('Testvej 1');
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v1/lists/7/items/2'));

    $c->call('fjern', 999);
    Http::assertNotSent(fn (Request $r) => str_ends_with($r->url(), '/items/999'));
    expect(fn () => $c->set('liste.items.0.url', 'javascript:alert(1)'))->toThrow(\Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException::class);

    $c->call('startOmdoeb')->assertSet('omdoebTil', 'Pant')->set('omdoebTil', 'Nyt navn')->call('omdoeb')->assertSet('omdoeber', false)->assertSee('Nyt navn');
    Http::assertSent(fn (Request $r) => $r->method() === 'PATCH' && $r->data() === ['name' => 'Nyt navn']);

    $c->call('sletListe')->assertRedirect(route('metis.lists'));
    Http::assertSent(fn (Request $r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/v1/lists/7'));
});

it('en liste der ikke findes (eller er en andens) siger det, i stedet for at vise en tom liste', function () {
    listePilot();
    Http::fake(['*' => Http::response(['message' => 'Not found'], 404)]);

    Livewire::test(Lists::class, ['id' => 7])->assertSee('Listen findes ikke')->assertDontSee('Listen er tom');
});

it('knappen og noterne står på selskabs- og ejendomssiden for piloter, ikke på personsiden og ikke uden adgang', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    $this->get('/lookup/cvr/12345678')->assertOk()->assertDontSee('data-liste-menu', false)->assertDontSee('data-noter', false);

    listePilot();
    $this->get('/lookup/cvr/12345678')->assertOk()->assertSee('data-liste-menu', false)->assertSee('data-noter', false);
    $this->get('/lookup/address/'.rawurlencode('Testvej 1, 2100 København Ø'))->assertOk()->assertSee('data-liste-menu', false)->assertSee('data-noter', false);
    $this->get('/lookup/name/'.rawurlencode('Jens Jensen'))->assertDontSee('data-liste-menu', false)->assertDontSee('data-noter', false);
});

it('🚨 API-laget selv afviser uden eget token, også når gating er slået fra (ellers bruges den fælles nøgle)', function () {
    config()->set('metis.gating.enabled', false);
    Http::fake();

    expect(app(\TheFountainhead\Metis\Services\RegistryApi::class)->egneData('get', '/v1/lists'))->toBe(['error' => 'pilot_required', 'status' => 403]);
    Http::assertNothingSent();
});

it('🚨 komponenterne selv spørger aldrig API-laget uden adgang, heller ikke når adgangen forsvinder efter indlæsning', function () {
    listePilot();
    Http::fake([
        '*/v1/notes?*' => Http::response(['data' => [['id' => 4, 'body' => 'Min']]]),
        '*/v1/lists?*' => Http::response(listeSvar([['id' => 2, 'name' => 'A', 'contains' => false]])),
    ]);
    $noter = Livewire::test(Notes::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')->assertSet('noter.0.id', 4);
    $knap = Livewire::test(ListButton::class, ['type' => 'cvr', 'query' => '12345678'])->call('hent')->assertSet('lister.0.id', 2);

    // Sessionen udløber. API-laget erstattes af en dublet, så det er komponentens EGEN vagt der prøves.
    session()->forget(['metis_user_token', 'metis_pilot_verificeret']);
    $this->mock(\TheFountainhead\Metis\Services\RegistryApi::class)->shouldNotReceive('egneData', 'resolveAddressAnalysis');

    $noter->call('slet', 4)->call('ret', 4)->assertSet('retterId', null)->set('tekst', 'x')->call('gem')->call('hent');
    $knap->call('skift', 2)->set('nytNavn', 'X')->call('opret')->call('hent');
});

it('🚨 siden Lister spørger heller ikke API-laget, når adgangen forsvinder efter indlæsning', function () {
    listePilot();
    Http::fake(['*/v1/lists/7' => Http::response(['data' => ['id' => 7, 'name' => 'Pant', 'items' => [['id' => 1, 'item_type' => 'company', 'item_value' => '12345678']]]])]);
    $c = Livewire::test(Lists::class, ['id' => 7])->assertSet('liste.id', 7);

    session()->forget(['metis_user_token', 'metis_pilot_verificeret']);
    $this->mock(\TheFountainhead\Metis\Services\RegistryApi::class)->shouldNotReceive('egneData');

    $c->call('fjern', 1)->set('omdoebTil', 'X')->call('omdoeb')->call('sletListe')->call('hent')->set('nytNavn', 'X')->call('opret');
});

it('rapporterer kun serverfejl: en afvisning (udløbet adgang, for mange kald) sender ikke brugerens noter med en fejlrapport', function (int $status, int $rapporter) {
    listePilot();
    \Illuminate\Support\Facades\Exceptions::fake();
    Http::fake(['*' => Http::response([], $status)]);

    app(\TheFountainhead\Metis\Services\RegistryApi::class)->egneData('get', '/v1/notes', ['item_type' => 'company', 'item_value' => '12345678']);

    \Illuminate\Support\Facades\Exceptions::assertReportedCount($rapporter);
})->with([[401, 0], [403, 0], [404, 0], [422, 0], [429, 0], [500, 1]]);
