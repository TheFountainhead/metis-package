<?php

use Illuminate\Support\Facades\Route;
use TheFountainhead\Metis\View\Components\MetisLink;

/*
 * 🚨 ÉN BUILDER, IKKE 12 HÅNDRULLEDE KALD.
 *
 * `MetisLink` har fire guards som de raa `route('metis.lookup', …)`-kald
 * IKKE har:
 *   1. tom query      => UrlGenerationException ("Missing required parameter")
 *   2. rute uregistreret => RouteNotFoundException => WHITE-SCREEN paa en
 *      kundevendt side (praecedens: signing-room PR #8, Gesda Flare 8664828)
 *   3. CPR i URL'en   => havner i browserhistorik og Referer (Googlebot
 *      hentede 35 unikke CPR-URL'er 9/8)
 *   4. degradering    => teksten bevares naar linket ikke kan bygges
 *
 * 🔑 Migrationen TILFOEJER altsaa beskyttelse hvert sted — den flytter ikke
 * bare kode. Og den fjerner behovet for `LookupLinkEncodingTest`, som efter
 * otte runder stadig kunne omgaas: en scanner der checker OM en builder blev
 * kaldt rigtigt er svagere end en builder der ikke KAN kaldes forkert.
 */

it('bygger urlFor() med samme regler som komponenten', function () {
    expect(MetisLink::urlFor('address', 'Søndergade 43A, 4653'))
        ->toBe(route('metis.lookup', [
            'type' => 'address',
            'query' => rawurlencode('Søndergade 43A, 4653'),
        ]));
});

it('urlFor() afviser en tom query i stedet for at kaste', function () {
    expect(MetisLink::urlFor('address', ''))->toBeNull()
        ->and(MetisLink::urlFor('address', '   '))->toBeNull();
});

it('urlFor() afviser et CPR uden for cpr-typen', function () {
    expect(MetisLink::urlFor('cvr', '123456-7890'))->toBeNull()
        ->and(MetisLink::urlFor('cpr', '123456-7890'))->not->toBeNull();
});

it('urlFor() returnerer null naar ruten ikke er registreret', function () {
    $beholdte = new Illuminate\Routing\RouteCollection;
    foreach (app('router')->getRoutes() as $r) {
        if ($r->getName() !== 'metis.lookup') {
            $beholdte->add($r);
        }
    }
    app('router')->setRoutes($beholdte);

    expect(Route::has('metis.lookup'))->toBeFalse()
        ->and(MetisLink::urlFor('address', 'Bredgade 40, 1260'))->toBeNull();
});

it('urlFor() encoder skraastreger — ingen sti-traversal', function () {
    $url = MetisLink::urlFor('address', '../../../admin');

    expect($url)->not->toContain('/lookup/address/../../../admin')
        ->and($url)->toContain('..%2F..%2F..%2Fadmin');
});

/*
 * 🚨 INGEN RAA route('metis.lookup') TILBAGE.
 *
 * Dette erstatter `LookupLinkEncodingTest`: i stedet for at scanne efter
 * KORREKT brug af en farlig konstruktion, forbyder vi konstruktionen og
 * peger paa builderen. Positivt formuleret invariant frem for et
 * moenster-katalog over omgaaelser.
 */
it('har ingen raa route(metis.lookup) uden for MetisLink', function () {
    $rødder = array_filter([
        realpath(__DIR__.'/../../src'),
        realpath(__DIR__.'/../../resources'),
        realpath(__DIR__.'/../../routes'),
    ]);

    $synder = [];

    foreach ($rødder as $rod) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rod));

        foreach ($it as $f) {
            // MetisLink ER builderen. Rutefilerne DEFINERER ruten
            // (`->name('metis.lookup')`) — begge er per definition ikke
            // kaldesteder.
            if ($f->getExtension() !== 'php'
                || str_contains($f->getPathname(), 'MetisLink.php')
                || str_contains($f->getPathname(), '/routes/')) {
                continue;
            }

            foreach (metisKodeTokens($f->getPathname()) as $i => $t) {
                if (! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                if (trim($t[1], "\"'") === 'metis.lookup') {
                    $synder[] = basename($f->getPathname()).':'.($t[2] ?? 0);
                }
            }
        }
    }

    sort($synder);
    expect($synder)->toBe([]);
});
