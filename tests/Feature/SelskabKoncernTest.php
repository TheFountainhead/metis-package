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
        'cvr' => '42824577', 'er_top' => false, 'top' => ['cvr' => '43048155', 'navn' => 'ApS af 1986', 'ejendomme' => 0],
        'led_til_top' => 5, 'sikker' => true, 'ring' => false, 'afkortet' => false,
        'selskaber_i_alt' => 31, 'ejendomme_i_alt' => 24,
        'selskaber' => [
            ['cvr' => '12498683', 'navn' => 'LIPER LEGACY A/S', 'led_fra_top' => 1, 'ejendomme' => 12],
            ['cvr' => '42824577', 'navn' => 'Ejendomsselskabet Laurentsvej 18-22 ApS', 'led_fra_top' => 5, 'ejendomme' => 10],
        ],
    ]];
}

it('viser toppen, tallene og selskaberne med link, og markerer det aktuelle selskab', function () {
    Http::fake(['*/v1/company/42824577/koncern' => Http::response(koncernSvar())]);

    $html = Livewire::test('metis-company-koncern', ['query' => '42824577'])->html();

    expect($html)->toContain('Selskabet indgår i koncernen under')->toContain('ApS af 1986')
        ->toContain('31 selskaber og 24 ejendomme.')->toContain('LIPER LEGACY A/S')
        ->toContain('/lookup/cvr/43048155')->toContain('/lookup/cvr/12498683')
        ->toContain('+ 28 flere selskaber')
        ->and(substr_count($html, 'data-koncern-raekke'))->toBe(3)
        ->and(substr_count($html, 'dette selskab'))->toBe(1)
        ->and($html)->not->toContain('præcis 50 %');
});

it('toppen selv ser sig som top', function () {
    Http::fake(['*' => Http::response(koncernSvar(['cvr' => '43048155', 'er_top' => true, 'led_til_top' => 0]))]);

    $html = Livewire::test('metis-company-koncern', ['query' => '43048155'])->html();

    expect($html)->toContain('Selskabet står i toppen af en koncern med 31 selskaber og 24 ejendomme.')
        ->not->toContain('indgår i koncernen under');
});

it('viser forbeholdene fra koncernreglen', function () {
    Http::fake(['*' => Http::sequence()
        ->push(koncernSvar(['sikker' => false]))
        ->push(koncernSvar(['cvr' => '11111111', 'afkortet' => true]))
        ->push(koncernSvar(['cvr' => '22222222', 'ring' => true])),
    ]);

    expect(Livewire::test('metis-company-koncern', ['query' => '42824577'])->html())->toContain('præcis 50 %')
        ->and(Livewire::test('metis-company-koncern', ['query' => '11111111'])->html())->toContain('kun en del af koncernen')
        ->and(Livewire::test('metis-company-koncern', ['query' => '22222222'])->html())->toContain('ejer hinanden i ring');
});

it('vises ikke for et selskab uden koncern, ved fejl eller mod et ældre API', function () {
    Http::fake(['*' => Http::sequence()
        ->push(koncernSvar(['selskaber_i_alt' => 1, 'selskaber' => []]))
        ->push([], 500)
        ->push(['message' => 'Not found'], 404),
    ]);

    foreach (['42824577', '11111111', '22222222'] as $cvr) {
        expect(Livewire::test('metis-company-koncern', ['query' => $cvr])->html())->not->toContain('Koncern');
    }
});

it('cacher et gyldigt svar, men aldrig en fejl', function () {
    Http::fake(['*' => Http::sequence()->push([], 500)->push(koncernSvar())->push([], 500)]);
    $api = app(\TheFountainhead\Metis\Services\RegistryApi::class);

    expect($api->fetchCompanyKoncern('42824577'))->toBeNull()
        ->and($api->fetchCompanyKoncern('42824577')['selskaber_i_alt'])->toBe(31)
        ->and($api->fetchCompanyKoncern('42824577')['selskaber_i_alt'])->toBe(31); // fra cache, ikke det tredje (fejlende) kald
    Http::assertSentCount(2);
});

it('cacher heller ikke et tomt eller ufuldstændigt 200-svar', function () {
    Http::fake(['*' => Http::sequence()->push(['data' => []])->push(koncernSvar())]);
    $api = app(\TheFountainhead\Metis\Services\RegistryApi::class);

    expect($api->fetchCompanyKoncern('42824577'))->toBeNull()
        ->and($api->fetchCompanyKoncern('42824577')['selskaber_i_alt'])->toBe(31);
});

it('står på selskabssiden, ikke på adressesiden', function () {
    Http::fake(['*' => Http::response(['data' => []])]);

    expect($this->get('/lookup/cvr/42824577')->assertOk()->getContent())->toContain('metis-company-koncern')
        ->and($this->get('/lookup/address/Roskildevej%2010%2C%202000%20Frederiksberg')->getContent())->not->toContain('metis-company-koncern');
});
