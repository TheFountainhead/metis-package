<?php

/*
 * 🚨 ALLE metis.lookup-links skal rawurlencode'e deres query.
 *
 * `route()` lader '/', '?' og '#' staa raat i et sti-segment. En adresse som
 * '../../../admin' bliver derfor til /lookup/address/../../../admin, som
 * browseren normaliserer VAEK fra /lookup/address/ foer afsendelse.
 *
 * Kilderne er ikke vores: registry-api-svar (analytics, debt-search) og
 * brugerens egen soegehistorik (index — enhver streng i /lookup/{type}/{query}
 * gemmes i metis_lookups og gengives som link paa forsiden).
 *
 * 🔑 `MetisLink.php:87` har gjort det rigtigt hele tiden. Denne test er vagten
 * der sikrer at det femte kaldested ikke arver faelden — samme princip som
 * chokepunkt-guarden i RegistryApi.
 */
it('rawurlencoder query i hvert metis.lookup-link i blades', function () {
    $dir = realpath(__DIR__.'/../../resources/views');
    $synder = [];

    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if (! str_ends_with($f->getFilename(), '.blade.php')) {
            continue;
        }

        foreach (file($f->getPathname()) as $nr => $linje) {
            if (! str_contains($linje, "route('metis.lookup'")) {
                continue;
            }

            // 'query' => rawurlencode(...) ELLER en variabel der allerede er encodet
            if (! str_contains($linje, 'rawurlencode')) {
                $synder[] = str_replace($dir.'/', '', $f->getPathname()).':'.($nr + 1);
            }
        }
    }

    sort($synder);
    expect($synder)->toBe([]);
});
