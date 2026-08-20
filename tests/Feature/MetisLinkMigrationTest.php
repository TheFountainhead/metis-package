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
                if (! is_array($t)) {
                    continue;
                }

                // 🚨 TRE TOKEN-TYPER, ikke én. Soegefeltet har vaeret for
                // smalt elleve gange i denne sag; her var dimensionen
                // TOKEN-TYPE:
                //
                //   '/lookup/'.$q   => T_CONSTANT_ENCAPSED_STRING   (saas)
                //   "/lookup/$q"    => T_ENCAPSED_AND_WHITESPACE    (BLIND)
                //   <a href="/…">   => T_INLINE_HTML                (saas)
                //
                // 🪤 En /lookup/-URL indeholder ALTID en variabel, saa
                // interpolation er den NATURLIGE maade at skrive den paa.
                // Vagten var blind for praecis den form der betyder noget.
                // Maalt: `@php($u = "/lookup/cpr/{$navn}")` slap igennem og
                // omgik CPR-guarden helt.
                //
                // 🪤 Og `href=`-ankeret var for snaevert: `href = "…"` med
                // mellemrum, `action="…"`, en absolut URL og Alpines
                // `:href="'/lookup/…' + q"` (BRUGT i ownership-graph.blade)
                // slap alle forbi. Vi matcher nu `/lookup/` hvor som helst i
                // HTML'en.
                if (! in_array($t[0], [
                    T_INLINE_HTML,
                    T_CONSTANT_ENCAPSED_STRING,
                    T_ENCAPSED_AND_WHITESPACE,
                ], true)) {
                    continue;
                }

                if ($t[0] === T_INLINE_HTML) {
                    if (str_contains($t[1], '/lookup/')) {
                        $synder[] = basename($f->getPathname()).':'.($t[2] ?? 0);
                    }

                    continue;
                }

                $vaerdi = trim($t[1], "\"'");

                // 🪤 REVIEW-FUND: at binde paa RUTENAVNET alene er den
                // snaevreste soegning endnu. Maalt — alle disse byggede den
                // praecis samme usikre URL og slap igennem:
                //
                //   url()->route(…) / URL::route(…) / app('url')->route(…)
                //   url('/lookup/address/'.$q)          strengkonkatenering
                //   redirect()->route(…)
                //   <a href="/lookup/address/{{ $x }}"> raa blade-anchor
                //
                // De to sidste er de REELLE risici — naturlige at skrive,
                // ingen literal at fange. Vi binder derfor ogsaa paa STIEN.
                if ($vaerdi === 'metis.lookup' || str_contains($vaerdi, '/lookup/')) {
                    $synder[] = basename($f->getPathname()).':'.($t[2] ?? 0);
                }
            }
        }
    }

    sort($synder);
    expect($synder)->toBe([]);
});

/*
 * 🚨 REVIEW-FUND: `redirect(null)` er et TAVST no-op.
 *
 * `urlFor()` returnerer `?string`, og de fem redirect-kaldesteder sendte den
 * direkte videre. Livewires `redirect()` typehinter loest og gemmer null uden
 * at kaste: brugeren klikker, og INTET sker — ingen fejl, ingen besked.
 *
 * 🪤 Foer migrationen kastede `route()` ved tom query, saa tilstanden var
 * SYNLIG (om end som en white-screen). Migrationen gjorde den robust og
 * dermed TAVS. "Brugeren klikker og intet sker" er praecis den tilstand hele
 * denne sag handler om.
 *
 * ⇒ Kan URL'en ikke bygges, sendes brugeren til forsiden frem for ingenting.
 */
it('sender til forsiden i stedet for at goere INTET naar URL en ikke kan bygges', function () {
    expect(TheFountainhead\Metis\View\Components\MetisLink::urlForEllerHjem('cvr', ''))
        ->toBe(route('metis.home'))
        ->and(TheFountainhead\Metis\View\Components\MetisLink::urlForEllerHjem('address', '   '))
        ->toBe(route('metis.home'));
});

it('urlForEllerHjem giver den RIGTIGE url naar den kan bygges', function () {
    expect(TheFountainhead\Metis\View\Components\MetisLink::urlForEllerHjem('address', 'Bredgade 40, 1260'))
        ->toBe(TheFountainhead\Metis\View\Components\MetisLink::urlFor('address', 'Bredgade 40, 1260'));
});
