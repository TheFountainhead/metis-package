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
        ['creditor' => 'Uden felt', 'principal' => 1000000],
        ['creditor' => 'Tomme', 'principal' => 1000000, 'detaljer' => ['prioritet' => null, 'tinglyst' => null, 'laantype' => null, 'rentetype' => null, 'referencerente' => null, 'saerlige_vilkaar' => [], 'tinglysningsafgift_kr' => null, 'ogsaa_lyst_paa' => null, 'respekterer' => 0]],
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
