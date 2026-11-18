<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;

/**
 * Pantebreve på ejendommens tinglysningsfane kan foldes ud med det Tinglysningen oplyser om lånet.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', false);
    Http::preventStrayRequests();
});

function pantEjendom(array $mortgages): void
{
    Http::fake(['*' => Http::response(['data' => ['property' => ['mortgages' => $mortgages, 'total_debt' => 1, 'tinglysning_synced_at' => '2026-11-18T08:00:00Z']]])]);
}

it('folder et pantebrev ud med rente, lånetype, vilkår, afgift og sampant', function () {
    pantEjendom([[
        'creditor' => 'NYKREDIT REALKREDIT A/S', 'type' => 'realkreditpantebrev', 'principal' => 26700000, 'interest_rate' => 2.68,
        'detaljer' => [
            'prioritet' => 4, 'tinglyst' => '2026-11-10', 'laantype' => 'kontantlaan', 'rentetype' => 'variabel',
            'referencerente' => ['navn' => 'CIBOR3', 'tillaeg' => 0.04], 'saerlige_vilkaar' => ['rentetilpasning'],
            'tinglysningsafgift_kr' => 14625, 'ogsaa_lyst_paa' => 26, 'respekterer' => 3,
        ],
    ]]);

    $html = Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])->html();

    expect($html)->toContain('aria-controls="pant-detalje-0"')->toContain('data-pant-detalje')->toContain('style="display:none"')
        ->toContain('variabel, CIBOR3 + 0,04 %')->toContain('Kontantlån')->toContain('Rentetilpasning')
        ->toContain('14.625 kr.')->toContain('26 andre ejendomme')->toContain('3 foranstående rettigheder')
        ->toContain('10.11.2026')->toContain('Realkreditpantebrev');
});

it('viser fradrag med minus og ental ved én', function () {
    pantEjendom([[
        'creditor' => 'Bank', 'principal' => 1000000,
        'detaljer' => ['referencerente' => ['navn' => 'CITA6', 'tillaeg' => -0.1], 'ogsaa_lyst_paa' => 1, 'respekterer' => 1, 'saerlige_vilkaar' => []],
    ]]);

    $html = Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])->html();

    expect($html)->toContain('CITA6 − 0,10 %')->toContain('1 anden ejendom')->toContain('1 foranstående rettighed')
        ->not->toContain('foranstående rettigheder');
});

it('et pantebrev uden detaljer er ikke klikbart (ældre API eller intet oplyst)', function () {
    pantEjendom([
        // 🪤 `type` står ALTID på rækken i det rigtige API; den alene må ikke gøre rækken klikbar.
        ['creditor' => 'Uden felt', 'type' => 'realkreditpantebrev', 'principal' => 1000000],
        ['creditor' => 'Tomme', 'type' => 'realkreditpantebrev', 'principal' => 1000000, 'detaljer' => ['prioritet' => null, 'tinglyst' => null, 'laantype' => null, 'rentetype' => null, 'referencerente' => null, 'saerlige_vilkaar' => [], 'tinglysningsafgift_kr' => null, 'ogsaa_lyst_paa' => null, 'respekterer' => 0]],
    ]);

    $html = Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])->html();

    expect($html)->toContain('Uden felt')->toContain('Tomme')->not->toContain('data-pant-detalje')->not->toContain('aria-controls');
});

it('folder kun det ud, der er indhold til', function () {
    pantEjendom([
        ['creditor' => 'Med', 'principal' => 1, 'detaljer' => ['prioritet' => 2, 'saerlige_vilkaar' => [], 'ogsaa_lyst_paa' => 0, 'respekterer' => 0]],
        ['creditor' => 'Uden', 'principal' => 1],
    ]);

    $html = Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])->html();

    expect(substr_count($html, 'data-pant-detalje'))->toBe(1)
        ->and($html)->not->toContain('Tinglysningsafgift')->not->toContain('Også lyst på');
});

it('🚨 kalder et afgiftspantebrev for det, det er, og bruger danske betegnelser', function () {
    pantEjendom([
        ['creditor' => 'A', 'type' => 'ejerpantebrev', 'is_afgiftspantebrev' => true, 'principal' => 1, 'detaljer' => ['prioritet' => 1, 'saerlige_vilkaar' => []]],
        ['creditor' => 'B', 'type' => 'skadesloesbrev', 'principal' => 1, 'detaljer' => ['prioritet' => 2, 'laantype' => 'serielaan', 'saerlige_vilkaar' => ['afdragsfri_periode']]],
        ['creditor' => 'C', 'type' => 'udlaeg', 'principal' => 1, 'detaljer' => ['prioritet' => 3, 'saerlige_vilkaar' => []]],
    ]);

    $html = Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])->html();

    expect($html)->toContain('Afgiftspantebrev (ikke gæld)')->not->toContain('>Ejerpantebrev<')
        ->toContain('Skadesløsbrev')->toContain('Udlæg')->toContain('Serielån')->toContain('Afdragsfri periode')
        ->not->toContain('Skadesloesbrev')->not->toContain('Udlaeg');
});

it('afrunder ikke rentetillægget og tåler skæve værdier uden at vælte sektionen', function () {
    pantEjendom([
        ['creditor' => 'A', 'principal' => 1, 'detaljer' => ['referencerente' => ['navn' => 'CIBOR3', 'tillaeg' => 0.125], 'tinglyst' => 'ikke-en-dato', 'laantype' => ['x'], 'saerlige_vilkaar' => []]],
        ['creditor' => 'B', 'principal' => 1, 'detaljer' => ['referencerente' => ['navn' => 'CIBOR6', 'tillaeg' => 0.004], 'tinglyst' => '0000-00-00', 'rentetype' => 'rentefri', 'saerlige_vilkaar' => []]],
        ['creditor' => 'C', 'principal' => 1, 'detaljer' => ['rentetype' => 'rentefri', 'prioritet' => 1, 'saerlige_vilkaar' => []]],
    ]);

    $html = Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])->html();

    expect($html)->toContain('CIBOR3 + 0,125 %')->toContain('CIBOR6 + 0,004 %')
        ->not->toContain('0,13 %')->not->toContain('0,00 %')->not->toContain('-0001')->not->toContain('Tinglyst');
    // En ukendt rentetype giver ingen rentelinje (aldrig et falsk "variabel").
    expect(substr_count($html, '>Rente<'))->toBe(2);
});

it('rækkerne har stabil nøgle og tilgængelig knap', function () {
    pantEjendom([['creditor' => 'A', 'rettighed_identifikator' => 'abc-123', 'principal' => 1, 'detaljer' => ['prioritet' => 1, 'saerlige_vilkaar' => []]]]);

    $html = Livewire::test('metis-address-mortgages', ['query' => 'Testvej 1, 2100'])->html();

    expect($html)->toContain('wire:key="pant-abc-123"')->toContain('aria-expanded="false"')->toContain('aria-hidden="true"');
});
