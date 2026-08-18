<?php

use Illuminate\Support\Facades\Route;
use TheFountainhead\Metis\View\Components\MetisLink;

/**
 * <x-metis-link> byggede sin URL med et ubeskyttet route()-kald og sendte
 * $query RAA. Komponenten bruges 17 steder — midt i tabeller, kort og
 * graf-noder — saa begge fejl rammer bredt.
 *
 * 1) Ubeskyttet route(): kaster RouteNotFoundException hvis `metis.lookup`
 *    ikke er i nameList. Det sker i embedded mode (routes/embedded.php
 *    registreres KUN hvis host-appen selv kalder embeddedRoutes(), og intet
 *    i pakken goer det) og ved URL-shadow fra host'ens egne ruter.
 *    Resultat: white-screen paa en kundevendt side, ikke et manglende link.
 *    Praecedens: signing-room PR #8 / compound_signing_room_url_shadow —
 *    6 af 6 tenants ramt.
 *
 * 2) Raa $query: rutesegmentet er `->where('query', '.*')`, saa Laravel
 *    efterlader `?` og `#` raa. Browseren afkorter ved dem, og opslaget
 *    rammer en ANDEN person. Intet 404, ingen fejl.
 *
 * 🪤 Suiten kunne ikke se (1): tests/TestCase.php:37 loader kun
 * routes/web.php. Denne fil afmelder ruten eksplicit for at naa tilstanden.
 */
it('bygger en URL naar ruten findes', function () {
    expect((new MetisLink('cvr', '45170209'))->url())
        ->toContain('/lookup/cvr/45170209');
});

it('🚨 returnerer null i stedet for at kaste naar ruten IKKE er registreret', function () {
    // Konstruér tilstanden: en tom RouteCollection uden metis.lookup.
    // Uden guarden kaster route() RouteNotFoundException herfra.
    Route::setRoutes(new \Illuminate\Routing\RouteCollection);

    expect(Route::has('metis.lookup'))->toBeFalse()
        ->and((new MetisLink('cvr', '45170209'))->url())->toBeNull();
});

it('🪤 encoder "?" saa opslaget ikke afkortes tavst', function () {
    $url = (new MetisLink('person', 'Navn?med=query'))->url();

    expect($url)->toContain('Navn%3Fmed%3Dquery')
        ->and($url)->not->toContain('Navn?med=query');
});

it('🪤 encoder "#" — alt efter et fragment-tegn falder ellers bort', function () {
    $url = (new MetisLink('person', 'Larnæs#anchor'))->url();

    expect($url)->toContain('%23anchor')
        ->and($url)->not->toContain('#anchor');
});

it('lader adresser og CVR passere uaendret gennem encodingen', function () {
    // Verificeret mod prod: begge former dekodes til samme vaerdi i
    // Lookup::mount(), saa encoding aendrer ikke hvad brugeren rammer.
    expect((new MetisLink('cvr', '45170209'))->url())->toContain('/lookup/cvr/45170209')
        ->and((new MetisLink('address', 'Bredgade 40, 1260 København'))->url())
        ->toContain('Bredgade%2040%2C%201260%20K%C3%B8benhavn');
});
