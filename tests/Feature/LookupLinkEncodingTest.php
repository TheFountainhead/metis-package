<?php

/*
 * 🚨 ALLE metis.lookup-links skal rawurlencode'e deres QUERY.
 *
 * `route()` lader '/', '?' og '#' staa raat i et sti-segment. En vaerdi som
 * '../../../admin' bliver til /lookup/address/../../../admin, som browseren
 * normaliserer VAEK fra /lookup/address/ foer afsendelse.
 *
 * Kilderne er ikke vores: registry-api-svar og brugerens egen soegehistorik
 * (enhver streng i /lookup/{type}/{query} gemmes i metis_lookups og gengives
 * som link paa forsiden).
 *
 * 🪤 FOERSTE UDKAST SCANNEDE KUN BLADES. Fem PHP-kaldesteder var strukturelt
 * usynlige — heraf `Index.php:114` og `Lookup.php:283`, begge med
 * brugerstyret input. Samme maalefejl som doer 15: jeg scopede soegningen
 * til en FILTYPE i stedet for til SINKET. Tredje gang i denne sag.
 *
 * 🪤 OG DEN TJEKKEDE KUN AT ORDET FANDTES PAA LINJEN. Review viste at
 * `['type' => rawurlencode($t), 'query' => $raa]` passerede — encodingen sad
 * paa den harmloese noegle. Vi kraever nu at den staar paa 'query' selv.
 */
it('rawurlencoder query i hvert metis.lookup-link', function () {
    $rødder = [
        realpath(__DIR__.'/../../src'),
        realpath(__DIR__.'/../../resources/views'),
    ];

    $synder = [];

    foreach ($rødder as $rod) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rod));

        foreach ($it as $f) {
            if (! in_array($f->getExtension(), ['php'], true)) {
                continue;
            }

            // MetisLink er DEN kanoniske builder og encoder korrekt (:87).
            if (str_contains($f->getPathname(), 'MetisLink.php')) {
                continue;
            }

            foreach (file($f->getPathname()) as $nr => $linje) {
                if (! str_contains($linje, "route('metis.lookup'")) {
                    continue;
                }

                // Kraev rawurlencode PAA query-vaerdien, ikke bare et sted paa linjen.
                if (! preg_match("/'query'\s*=>\s*rawurlencode\(/", $linje)) {
                    $synder[] = str_replace([$rod.'/', dirname($rod).'/'], '', $f->getPathname()).':'.($nr + 1);
                }
            }
        }
    }

    sort($synder);
    expect($synder)->toBe([]);
});
