<?php

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Sections\CompanyTinglysning;

beforeEach(function () {
    // 🚨 fetchCompanyTinglysningOverview() cacher paa cvr+filters. Uden flush
    // laeser test 2 test 1's svar, og begge maalinger bliver meningsloese.
    \Illuminate\Support\Facades\Cache::flush();

    if (! Route::has('metis.lookup')) {
        Route::get('/lookup/{type}/{query}', fn () => null)->name('metis.lookup')->where('query', '.*');
    }
});

/**
 * Afgiftspantebreve misbruger felterne: HaeftelseType siger 'ejerpantebrev',
 * men dokumentet ER et afgiftspantebrev. Tinglysningen markerer det i et
 * SEPARAT felt, TinglysningAfgiftOverfoerselIndikator.
 *
 * Maalt i prod 1/8 paa Akacietorvet 2A (BFE 250960):
 *
 *   319.660 kr  HaeftelseType='ejerpantebrev'  AfgiftIndikator='true'
 *   176.638 kr  HaeftelseType='ejerpantebrev'  AfgiftIndikator='true'
 *
 * Resights viser dem korrekt som "Afgiftspantebrev". Vi viste 'ejerpantebrev',
 * fordi visningen laeste den raa type og ignorerede flaget.
 *
 * 🔑 API'et BEREGNEDE flaget hele tiden (StreamTinglysningMortgages:369 kalder
 * TinglysningSync::isAfgiftspantebrev, og MortgageRowResource:45 sender det
 * videre som is_afgiftspantebrev). Visningen brugte det bare aldrig.
 *
 * Hvorfor det betyder noget: et afgiftspantebrev er IKKE gaeld. Det er et
 * dokument der overfoerer en betalt tinglysningsafgift til et nyt pant. At
 * vise det som ejerpantebrev faar det til at ligne en gaeldspost.
 */
function afgiftOverview(bool $isAfgift): array
{
    // Ingen 'data'-wrapper: fetchCompanyTinglysningOverview() returnerer
    // svaret som det er, saa komponenten laeser 'mortgages_added' i roden.
    return [
        'company' => ['cvr' => '29798486', 'name' => 'AKACIETORVET ApS'],
        'tree_meta' => [
            'result_kind' => 'ok',
            'total_descendant_companies' => 0,
            'total_properties' => 1,
            'total_mortgages' => 1,
            'total_principal_amount' => 319_660_00,
            'weighted_ltv' => null,
            'tree_depth' => 0,
            'applied_tree_depth' => 1,
        ],
        'tier_breakdown' => [],
        'mortgages_added' => [[
            'id' => 3494419,
            'property_id' => 3330685,
            'address' => 'Akacietorvet 2A, 3520 Farum',
            'bfe' => '250960',
            'owner_company' => ['cvr' => '29798486', 'name' => 'AKACIETORVET ApS'],
            'tier_depth' => 0,
            'mortgage_type' => 'ejerpantebrev',
            'creditor' => 'Akacietorvet ApS',
            'debitor' => null,
            'priority' => 13,
            'principal_amount' => 319_660_00,
            'registration_date' => '2000-01-26',
            'is_active' => true,
            'is_sampant' => false,
            'is_afgiftspantebrev' => $isAfgift,
            'underpant' => [],
            'ltv' => ['value' => null, 'method' => 'unavailable'],
        ]],
        'streaming' => ['complete' => true, 'cursor' => null, 'total_expected' => 1, 'delivered_so_far' => 1],
    ];
}

it('viser Afgiftspantebrev naar flaget er sat, ikke den raa HaeftelseType', function () {
    Http::fake(['*tinglysning-overview*' => Http::response(afgiftOverview(true))]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '29798486']);

    // Bekraeft foerst at raekken FAKTISK er loadet — ellers beviser en
    // tekst-assertion intet (filterpanelet indeholder ordene i forvejen).
    expect($component->get('mortgages'))->toHaveCount(1);

    $html = $component->html();
    expect($html)->toContain('Akacietorvet 2A')
        ->and($html)->toContain('Afgiftspantebrev');
});

it('viser den almindelige type naar flaget IKKE er sat', function () {
    // Vaernet maa kun ramme afgiftspantebreve. Et aegte ejerpantebrev skal
    // fortsat vises som ejerpantebrev — ellers har vi byttet én fejl ud med
    // en anden. Akacietorvets 45 mio. og 25 mio. ER aegte ejerpantebreve.
    Http::fake(['*tinglysning-overview*' => Http::response(afgiftOverview(false))]);

    $component = Livewire::test(CompanyTinglysning::class, ['query' => '29798486']);

    expect($component->get('mortgages'))->toHaveCount(1);

    // 🚨 Kan IKKE assertes med toContain('ejerpantebrev'): ordet staar ogsaa i
    // filterpanelet ("Pantebrevs-typer: Privat / Realkredit / Ejer / Afgift"),
    // saa den assertion ville vaere groen uanset om raekken renderes.
    // Foerste udkast af denne test var groen af praecis den grund.
    $html = $component->html();
    expect($html)->toContain('Akacietorvet 2A')
        ->and(substr_count($html, 'Afgiftspantebrev'))->toBe(0);
});


