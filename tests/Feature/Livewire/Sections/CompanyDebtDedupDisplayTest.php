<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyOverview;
use TheFountainhead\Metis\Livewire\Sections\CompanyProperties;

beforeEach(function () {
    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

/**
 * AKACIETORVET, maalt i prod 31/7.
 *
 * Tre ejerlejligheder deler pantebreve. Per-ejendoms-tallene er RIGTIGE hver for
 * sig — pantet haefter faktisk paa alle tre — men lagt sammen dobbelttaeller de:
 *
 *   per ejendom:  92.229.000 + 78.045.298 + 71.818.000 = 242.092.298
 *   API'ets total_debt (dedup over 13 dokumenter)      =  96.092.298
 *
 * Dobbelttaellingen opstaar VED sammenlaegningen, saa visningslaget kan ikke
 * selv opdage den: det ser kun tal der hver isaer er korrekte. Derfor skal
 * totalen laeses fra API'et, ikke genberegnes.
 */
function akacietorvetPortfolio(): array
{
    return ['data' => ['portfolio' => [
        'owner_type' => 'company',
        'owner_cvr' => '29798486',
        'owner_name' => 'AKACIETORVET ApS',
        'source' => 'koncern_bfe',
        'property_count' => 3,
        'total_count' => 3,
        'total_valuation' => 12_099_000,
        'total_area' => 928,
        'total_debt' => 96_092_298,
        'properties' => [
            ['matrikel_id' => '250959', 'address' => 'Akacietorvet 2', 'postal_code' => '3520', 'city' => 'Farum',
                'building_usage' => '321', 'valuation' => 6_200_000, 'total_debt' => 92_229_000],
            ['matrikel_id' => '250960', 'address' => 'Akacietorvet 2A', 'postal_code' => '3520', 'city' => 'Farum',
                'building_usage' => '321', 'valuation' => 2_800_000, 'total_debt' => 78_045_298],
            ['matrikel_id' => '250961', 'address' => 'Akacietorvet 2B', 'postal_code' => '3520', 'city' => 'Farum',
                'building_usage' => '321', 'valuation' => 3_099_000, 'total_debt' => 71_818_000],
        ],
    ]]];
}

it('KPI viser API-ets dedup-ede total, ikke summen af per-ejendoms-tal', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'AKACIETORVET ApS']]]),
        '*property-portfolio*' => Http::response(akacietorvetPortfolio()),
    ]);

    $component = Livewire::test(CompanyOverview::class, ['query' => '29798486']);

    expect($component->get('totalDebt'))->toBe(96_092_298);

    // Blade-tjek. Vaerdi og enhed rendres som separate variable, saa "96,1" og
    // "mio. kr" staar ikke sammenhaengende i HTML'en — der assertes paa tallet.
    // 242,1 maa ikke optraede nogen steder paa skaermen.
    $html = $component->html();
    expect($html)->toContain('96,1')
        ->and($html)->not->toContain('242,1');
});

it('ejendomsportefoeljens total laeses fra API-et, ikke genberegnet', function () {
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'AKACIETORVET ApS']]]),
        '*property-portfolio*' => Http::response(akacietorvetPortfolio()),
    ]);

    $html = Livewire::test(CompanyProperties::class, ['query' => '29798486'])->html();

    expect($html)->toContain('96.092.298')
        ->and($html)->not->toContain('242.092.298');
});

it('per-ejendoms-tallene staar UAENDREDE i tabellen', function () {
    // Dedup'en maa kun ramme TOTALEN. Hver raekke skal fortsat vise sin egen
    // gaeld — et pant der haefter paa tre lejligheder er reelt til stede paa
    // alle tre, og en bruger der ser paa én ejendom skal se det fulde beloeb.
    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'AKACIETORVET ApS']]]),
        '*property-portfolio*' => Http::response(akacietorvetPortfolio()),
    ]);

    $html = Livewire::test(CompanyProperties::class, ['query' => '29798486'])->html();

    expect($html)->toContain('92.229.000')
        ->and($html)->toContain('78.045.298')
        ->and($html)->toContain('71.818.000');
});

it('falder tilbage til summen naar API-et ikke leverer en total', function () {
    // Aeldre cache-poster og EJF-stien har intet total_debt. Da er summen af
    // per-ejendoms-tal det bedste vi har — bedre end at vise ingenting.
    $portfolio = akacietorvetPortfolio();
    unset($portfolio['data']['portfolio']['total_debt']);

    Http::fake([
        '*cvr/company/*' => Http::response(['data' => ['company' => ['name' => 'AKACIETORVET ApS']]]),
        '*property-portfolio*' => Http::response($portfolio),
    ]);

    expect(Livewire::test(CompanyOverview::class, ['query' => '29798486'])->get('totalDebt'))
        ->toBe(242_092_298);
});
