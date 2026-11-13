<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\CompanySegmentation;

uses(RefreshDatabase::class);

/**
 * Egen population (CVR-upload) og ekstern investering (ERST, lovet 29/10-2026).
 * Datalaget er registry-api #451; her vaernes ledningerne.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.registry_api.url', 'https://registry.test');
    config()->set('metis.registry_api.key', 'test-noegle');
    config()->set('metis.gating.enabled', false);
    // Egne populationer kraever et personligt token (RegistryApi::personligtTokenFejl).
    session(['metis_user_token' => 'personligt-token']);
});

function segSvar(array $meta = []): array
{
    return ['data' => [], 'meta' => ['total' => 0] + $meta];
}

it('finder CVR-numre i en CSV uanset skilletegn, praefiks og dubletter', function () {
    $csv = "cvr;navn\n12345678;Alfa ApS\nDK87654321,Beta\n\"12345678\"\n987654321\n1234567\n";

    expect(CompanySegmentation::cvrNumre($csv))->toBe(['12345678', '87654321']);
});

it('viser brugerens populationer og sender den valgte som filter', function () {
    $this->populationerSvar = ['data' => [['id' => 7, 'navn' => 'Sportstech', 'antal_cvr' => 120, 'antal_fundet' => 118]]];
    Http::fake(['*/company-segmentation' => Http::response(segSvar())]);

    Livewire::test(CompanySegmentation::class)
        ->assertSee('Sportstech (118)')
        ->set('population', '7');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/company-segmentation') && ($r->data()['population'] ?? null) === 7);
});

it('opretter en population fra indsatte CVR-numre, vaelger den og viser ukendte og ophoerte', function () {
    Http::fake([
        '*/company-segmentation/populations' => Http::response(['data' => [
            'id' => 9, 'navn' => 'Min liste', 'antal_cvr' => 3, 'antal_fundet' => 2, 'ukendte_cvr' => ['99999999'], 'ophoerte' => 1,
        ]], 201),
        '*/company-segmentation' => Http::response(segSvar()),
    ]);

    Livewire::test(CompanySegmentation::class)
        ->set('cvrTekst', "11111111\n22222222;99999999\n11111111")
        ->set('populationNavn', 'Min liste')
        ->call('opretPopulation')
        ->assertSet('population', '9')
        ->assertSet('cvrTekst', '')
        ->assertSee('2 af 3 CVR-numre findes i registret.')
        ->assertSee('Ukendte: 99999999.')
        ->assertSee('1 er ophørt');

    Http::assertSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/populations')
        && $r->data() === ['navn' => 'Min liste', 'cvr' => ['11111111', '22222222', '99999999']]);
    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/company-segmentation') && ($r->data()['population'] ?? null) === 9);
});

it('kalder ikke datalaget, naar der ingen CVR-numre er', function () {
    Http::fake(['*/company-segmentation' => Http::response(segSvar())]);

    Livewire::test(CompanySegmentation::class)
        ->set('cvrTekst', 'ingen numre her 1234')
        ->call('opretPopulation')
        ->assertSee('Der er ingen CVR-numre (8 cifre) i listen.');

    Http::assertNotSent(fn ($r) => $r->method() === 'POST' && str_ends_with($r->url(), '/populations'));
});

it('viser datalagets besked, naar populationen afvises', function () {
    Http::fake([
        '*/company-segmentation/populations' => Http::response(['message' => 'Højst 100 populationer. Slet en gammel først.'], 422),
        '*/company-segmentation' => Http::response(segSvar()),
    ]);

    Livewire::test(CompanySegmentation::class)
        ->set('cvrTekst', '11111111')
        ->call('opretPopulation')
        ->assertSee('Højst 100 populationer. Slet en gammel først.')
        ->assertSet('population', '');
});

it('sletter kun en population fra brugerens egen liste og rydder valget', function () {
    $this->populationerSvar = ['data' => [['id' => 7, 'navn' => 'A', 'antal_cvr' => 1, 'antal_fundet' => 1]]];
    Http::fake([
        '*/company-segmentation/populations/*' => Http::response(['data' => ['id' => 7, 'slettet' => true]]),
        '*/company-segmentation' => Http::response(segSvar()),
    ]);

    $c = Livewire::test(CompanySegmentation::class)->set('population', '7');

    $c->call('sletPopulation', 8);
    Http::assertNotSent(fn ($r) => $r->method() === 'DELETE');

    $c->call('sletPopulation', 7)->assertSet('population', '');
    Http::assertSent(fn ($r) => $r->method() === 'DELETE' && str_ends_with($r->url(), '/populations/7'));
});

it('sender ekstern-felterne som registry-api-parametrene', function (string $felt, mixed $vaerdi, string $noegle) {
    Http::fake(['*/company-segmentation' => Http::response(segSvar())]);

    Livewire::test(CompanySegmentation::class)->set($felt, $vaerdi);

    Http::assertSent(fn ($r) => ($r->data()[$noegle] ?? null) === $vaerdi);
})->with([
    'ja/nej' => ['eksternInvestering', 'ja', 'ekstern_investering'],
    'fra' => ['eksternFra', '2022-01-01', 'ekstern_fra'],
    'til' => ['eksternTil', '2024-12-31', 'ekstern_til'],
    'type' => ['eksternInvestortype', 'venture', 'ekstern_investortype'],
]);

it('dropper ukendte vaerdier fra URL-en i stedet for at vaelte siden', function () {
    Http::fake(['*/company-segmentation' => Http::response(segSvar())]);

    Livewire::withQueryParams(['population' => 'x1', 'ekstern' => 'maaske', 'ekstern_type' => 'drage'])
        ->test(CompanySegmentation::class)
        ->assertSet('population', '')
        ->assertSet('eksternInvestering', '')
        ->assertSet('eksternInvestortype', '');

    Http::assertNotSent(fn ($r) => array_intersect_key($r->data(), array_flip(['population', 'ekstern_investering', 'ekstern_investortype'])) !== []);
});

it('viser andelen ekstern, intern og ukendt fra datalaget', function () {
    Http::fake(['*/company-segmentation' => Http::response(segSvar(['kapitalkilde' => ['ekstern' => 30, 'intern' => 50, 'ukendt' => 20]]))]);

    Livewire::test(CompanySegmentation::class)
        ->set('eksternInvestering', 'ja')
        ->assertSee('30 ekstern, 50 intern og 20 ukendt (20,0 % ukendt');
});

it('har de samme ekstern-noegler som registry-api kender', function () {
    // Spejl af registry-api CompanySegmentation::EKSTERN_ETIKETTER/EKSTERN_TYPER (#451).
    expect(array_keys(CompanySegmentation::EKSTERN_VALG))->toBe(['ja', 'nej'])
        ->and(array_keys(CompanySegmentation::EKSTERN_TYPER))->toBe(['venture', 'kapitalfond', 'public', 'foundation', 'udenlandsk', 'holding', 'selskab', 'person']);
});

it('beholder populationen ved nulstil, men rydder ekstern-filtrene', function () {
    Http::fake(['*/company-segmentation' => Http::response(segSvar())]);

    Livewire::test(CompanySegmentation::class)
        ->set('population', '7')
        ->set('eksternInvestering', 'ja')
        ->call('nulstil')
        ->assertSet('population', '7')
        ->assertSet('eksternInvestering', '');
});

it('🚨 viser og bruger ingen populationer uden personligt token (faelles noegle)', function () {
    session()->forget('metis_user_token');
    $this->populationerSvar = ['data' => [['id' => 7, 'navn' => 'Andens liste', 'antal_cvr' => 1, 'antal_fundet' => 1]]];
    Http::fake(['*/company-segmentation' => Http::response(segSvar())]);

    Livewire::withQueryParams(['population' => '7'])
        ->test(CompanySegmentation::class)
        ->assertDontSee('Andens liste')
        ->assertDontSee('Upload egen liste med CVR-numre')
        ->assertSet('population', '')
        ->set('cvrTekst', '11111111')
        ->call('opretPopulation');

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/populations'));
    Http::assertNotSent(fn ($r) => array_key_exists('population', $r->data()));
});

it('🚨 RegistryApi kalder ikke populations-ruterne med den faelles noegle', function () {
    session()->forget('metis_user_token');
    $api = app(\TheFountainhead\Metis\Services\RegistryApi::class);

    expect($api->segmentPopulations())->toBe(['error' => 'personal_token_required'])
        ->and($api->createSegmentPopulation('x', ['11111111']))->toBe(['error' => 'personal_token_required'])
        ->and($api->deleteSegmentPopulation(7))->toBe(['error' => 'personal_token_required']);

    Http::assertNothingSent();
});
