<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * "Koncern" på selskabssiden: toppen og de selskaber der hænger sammen via selskabsejerskab.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', false);
    Cache::flush();
    Http::preventStrayRequests();
});

function koncernSvar(array $over = []): array
{
    return ['data' => $over + [
        'cvr' => '10000005', 'er_top' => false, 'top' => ['cvr' => '10000001', 'navn' => 'Top Holding ApS', 'ejendomme' => 0],
        'led_til_top' => 5, 'sikker' => true, 'ring' => false, 'afkortet' => false,
        'selskaber_i_alt' => 31, 'ejendomme_i_alt' => 24,
        'selskaber' => [
            ['cvr' => '10000002', 'navn' => 'Datter Et A/S', 'led_fra_top' => 1, 'ejendomme' => 12],
            ['cvr' => '10000005', 'navn' => 'Datter Fem ApS', 'led_fra_top' => 5, 'ejendomme' => 10],
        ],
    ]];
}

it('viser toppen, tallene og selskaberne med link, og markerer det aktuelle selskab', function () {
    Http::fake(['*/v1/company/10000005/koncern' => Http::response(koncernSvar())]);

    $html = Livewire::test('metis-company-koncern', ['query' => '10000005'])->html();

    expect($html)->toContain('Selskabet indgår i koncernen under')->toContain('Top Holding ApS')
        ->toContain('31 selskaber og 24 ejendomme.')->toContain('Datter Et A/S')
        ->toContain('/lookup/cvr/10000001')->toContain('/lookup/cvr/10000002')
        ->toContain('28 flere selskaber')
        ->and(substr_count($html, 'data-koncern-raekke'))->toBe(3)
        ->and(substr_count($html, '>dette selskab<'))->toBe(1) // mærket, ikke fodnotens tekst
        ->and($html)->not->toContain('data-koncern-forbehold');
});

it('toppen selv ser sig som top', function () {
    Http::fake(['*' => Http::response(koncernSvar(['cvr' => '10000001', 'er_top' => true, 'led_til_top' => 0]))]);

    $html = Livewire::test('metis-company-koncern', ['query' => '10000001'])->html();

    expect($html)->toContain('Selskabet står i toppen af en koncern med 31 selskaber og 24 ejendomme.')
        ->not->toContain('indgår i koncernen under');
});

it('viser alle forbehold der gælder, ikke kun ét', function () {
    Http::fake(['*' => Http::sequence()
        ->push(koncernSvar(['sikker' => false]))
        ->push(koncernSvar(['cvr' => '11111111', 'afkortet' => true, 'sikker' => false]))
        ->push(koncernSvar(['cvr' => '22222222', 'ring' => true])),
    ]);

    $en = Livewire::test('metis-company-koncern', ['query' => '10000005'])->html();
    $to = Livewire::test('metis-company-koncern', ['query' => '11111111'])->html();
    $ring = Livewire::test('metis-company-koncern', ['query' => '22222222'])->html();

    expect($en)->toContain('50 til 66,66 %')->and(substr_count($en, 'data-koncern-forbehold'))->toBe(1)
        ->and(substr_count($to, 'data-koncern-forbehold'))->toBe(2)
        ->and($to)->toContain('kun en del af koncernen')->toContain('50 til 66,66 %')
        ->and($ring)->toContain('ejer hinanden i ring');
});

it('vises ikke for et selskab uden koncern eller mod et ældre API (404)', function () {
    Http::fake(['*' => Http::sequence()
        ->push(koncernSvar(['selskaber_i_alt' => 1, 'selskaber' => []]))
        ->push(['message' => 'Not found'], 404),
    ]);

    foreach (['10000005', '11111111'] as $cvr) {
        expect(Livewire::test('metis-company-koncern', ['query' => $cvr])->html())->not->toContain('Koncern');
    }
});

it('🚨 en fejl ligner ikke "står alene": sektionen siger at oplysningerne ikke kunne hentes', function () {
    Http::fake(['*' => Http::sequence()->push([], 500)->push(['message' => 'Too many'], 429)->push(['data' => []])]);

    foreach (['10000005', '11111111', '22222222'] as $cvr) {
        expect(Livewire::test('metis-company-koncern', ['query' => $cvr])->html())
            ->toContain('kunne ikke hentes')->toContain('betyder ikke, at selskabet står alene');
    }
});

it('bøjer tallene: 2 selskaber og 1 ejendom', function () {
    Http::fake(['*' => Http::response(koncernSvar(['selskaber_i_alt' => 2, 'ejendomme_i_alt' => 1, 'selskaber' => [['cvr' => '10000002', 'navn' => 'Datter Et A/S', 'led_fra_top' => 1, 'ejendomme' => 1]]]))]);

    $html = Livewire::test('metis-company-koncern', ['query' => '10000002'])->html();

    expect($html)->toContain('2 selskaber og 1 ejendom.')->not->toContain('1 ejendomme');
});

it('tåler et svar der mangler felter uden at vælte siden', function () {
    Http::fake(['*' => Http::response(['data' => ['top' => ['cvr' => '10000001'], 'selskaber_i_alt' => 3, 'selskaber' => [['cvr' => '10000002'], 'skrot', []]]])]);

    $html = Livewire::test('metis-company-koncern', ['query' => '10000002'])->html();

    expect(substr_count($html, 'data-koncern-raekke'))->toBe(2);
});

it('🚨 henter intet for et selskab sessionen ikke har fået godkendt', function () {
    config()->set('metis.gating.enabled', true);
    Http::fake(['*' => Http::response(koncernSvar())]);

    $html = Livewire::test('metis-company-koncern', ['query' => '10000005'])->html();

    // Sektionens egen vagt afviser, før datalaget spørges: derfor heller ingen "kunne ikke hentes".
    expect($html)->not->toContain('Top Holding ApS')->not->toContain('kunne ikke hentes');
    Http::assertNothingSent();
});

it('cacher et gyldigt svar, men aldrig en fejl', function () {
    Http::fake(['*' => Http::sequence()->push([], 500)->push(koncernSvar())->push([], 500)]);
    $api = app(\TheFountainhead\Metis\Services\RegistryApi::class);

    expect($api->fetchCompanyKoncern('10000005'))->toBe(['error' => 500])
        ->and($api->fetchCompanyKoncern('10000005')['selskaber_i_alt'])->toBe(31)
        ->and($api->fetchCompanyKoncern('10000005')['selskaber_i_alt'])->toBe(31); // fra cache, ikke det tredje (fejlende) kald
    Http::assertSentCount(2);
});

it('cacher heller ikke et tomt eller ufuldstændigt 200-svar', function () {
    Http::fake(['*' => Http::sequence()->push(['data' => []])->push(koncernSvar())]);
    $api = app(\TheFountainhead\Metis\Services\RegistryApi::class);

    expect($api->fetchCompanyKoncern('10000005'))->toBe(['error' => 200])
        ->and($api->fetchCompanyKoncern('10000005')['selskaber_i_alt'])->toBe(31);
});

it('står på selskabssiden, ikke på adressesiden', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    expect($this->get('/lookup/cvr/10000005')->assertOk()->getContent())->toContain('metis-company-koncern')
        ->and($this->get('/lookup/address/Roskildevej%2010%2C%202000%20Frederiksberg')->getContent())->not->toContain('metis-company-koncern');
});
