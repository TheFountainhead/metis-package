<?php

use TheFountainhead\Metis\Services\RegistryApi;

/*
 * 🚨 ÉN DEFINITION AF "KAN DENNE ADRESSE OPLOESES", IKKE SYV.
 *
 * bcc8e89 tilfoejede `adresseKanOploeses()` med en docblock der PAASTOD at
 * vaere den ene definition — og lod saa alle kaldesteder beholde deres egen
 * haardkodede `empty(parseAddress(...)['zip'])`. Maalt: metoden blev brugt
 * NUL steder.
 *
 * Det er praecis den fejl kommentaren selv advarer imod (FIRE CPR-detektorer,
 * hvor den fjerde accepterede et format de tre andre afviste) — begaaet i
 * kommentaren der advarer imod den.
 *
 * Denne test fejler hvis nogen skriver definitionen igen i stedet for at
 * spoerge praedikatet.
 */
it('har ingen haardkodede kopier af adresse-praedikatet', function () {
    $rødder = [realpath(__DIR__.'/../../../src'), realpath(__DIR__.'/../../../resources')];
    $synder = [];

    foreach ($rødder as $rod) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rod));
        foreach ($it as $f) {
            if (! in_array($f->getExtension(), ['php'], true)) {
                continue;
            }

            foreach (file($f->getPathname()) as $nr => $linje) {
                // Selve definitionen inde i RegistryApi er undtaget.
                if (str_contains($linje, 'function adresseKanOploeses')) {
                    break;
                }

                if (preg_match("/parseAddress\([^)]*\)\['zip'\]/", $linje)) {
                    $synder[] = basename($f->getPathname()).':'.($nr + 1);
                }
            }
        }
    }

    sort($synder);
    expect($synder)->toBe([]);
});

it('praedikatet svarer det samme som chokepunkt-guarden', function () {
    $api = app(RegistryApi::class);

    // Ægte gyldige — maa ALDRIG afvises (regressionen fra b5165d8).
    foreach ([
        'Strandvejen 100 B, 2900 Hellerup',
        'Vestergade 1 A, 5000 Odense C',
        'Søndergade 43A, 4653 Karise',
    ] as $a) {
        expect($api->adresseKanOploeses($a))->toBeTrue($a);
    }

    // Uden postnummer — kan ikke oploeses til én matrikel.
    foreach ([
        'Søndergade 43A',
        'Bredgade 40',
        '',
    ] as $a) {
        expect($api->adresseKanOploeses($a))->toBeFalse($a);
    }
});
