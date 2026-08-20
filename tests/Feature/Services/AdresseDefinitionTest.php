<?php

use TheFountainhead\Metis\Services\RegistryApi;

/*
 * 🚨 ÉN DEFINITION AF "KAN DENNE ADRESSE OPLOESES", IKKE SYV.
 *
 * `adresseKanOploeses()` blev tilfoejet med en docblock der PAASTOD at vaere
 * den ene definition — og alle kaldesteder beholdt deres egen haardkodede
 * `empty(parseAddress(...)['zip'])`. Maalt: seks konkurrerende formuleringer.
 *
 * 🪤 TRE UDKAST AF DENNE TEST VAR SELV BLINDE — samme fejlklasse som det den
 * bevogter, én gang pr. runde:
 *   1. `break` i stedet for `continue` ⇒ scanningen stoppede ved definitionen
 *      paa linje 346, saa linje 347-1692 blev ALDRIG set. Maalt: en kopi
 *      indsat EFTER praedikatet gav groen test; byte-identisk kopi FOER gav roed.
 *   2. regex matchede kun den inlinede form ⇒ `empty($parsed['zip'])` mod en
 *      lokal variabel slap forbi. Det er praecis den form den sjette kopi brugte.
 *   3. ren tekstsoegning flagede min egen KOMMENTAR fordi den citerede koden.
 *
 * ⇒ Vi bruger nu PHPs egen tokenizer. Den ser kun KODE — kommentarer,
 * docblocks og strenge er per konstruktion uden for raekkevidde, og
 * linjeombrydning er ligegyldig fordi vi laeser tokens, ikke linjer.
 */
it('har ingen haardkodede kopier af adresse-praedikatet', function () {
    $rødder = [
        realpath(__DIR__.'/../../../src'),
        realpath(__DIR__.'/../../../resources'),
    ];

    $synder = [];

    foreach ($rødder as $rod) {
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($rod));

        foreach ($it as $f) {
            if ($f->getExtension() !== 'php') {
                continue;
            }

            $kode = metisKodeTokens($f->getPathname());

            // 🚨 LAAS PAA DEFINITIONEN, IKKE PAA IDENTIFIKATOREN.
            //
            // Foerste udkast satte undtagelsen paa ETHVERT forekomst af
            // `adresseKanOploeses` — ogsaa korrekte KALD. Maalt: `Lookup.php`
            // var undtaget fra linje 214 til filens slutning (45,5% af
            // filen), netop fordi den bruger praedikatet rigtigt.
            //
            // 🔑 En vagt hvis daekning SKRUMPER naar koden bliver rigtig.
            // At migrere til det korrekte API fjernede filen fra scanningen.
            // Sjette variant af "soegefeltet er smallere end det bevogtede" —
            // og den foerste hvor vagten belaenner adoption med blindhed.
            //
            // Kuren: find `function <navn>`, og undtag KUN indtil den
            // matchende afsluttende klammeparentes.
            $undtagne = [];
            $dybde = 0;
            $iKrop = false;

            foreach ($kode as $i => $t) {
                $tekst = is_array($t) ? $t[1] : $t;

                if (! $iKrop && is_array($t) && $t[0] === T_FUNCTION) {
                    $navn = is_array($kode[$i + 1] ?? null) ? $kode[$i + 1][1] : '';
                    if (in_array($navn, ['adresseKanOploeses', 'parsetAdresseKanOploeses'], true)) {
                        $iKrop = true;
                        $dybde = 0;
                    }
                }

                if ($iKrop) {
                    $undtagne[$i] = true;

                    if ($tekst === '{') {
                        $dybde++;
                    } elseif ($tekst === '}') {
                        $dybde--;
                        if ($dybde === 0) {
                            $iKrop = false;
                        }
                    }
                }
            }

            foreach ($kode as $i => $t) {
                if (isset($undtagne[$i])) {
                    continue;
                }

                // 🪤 Normalisér anfoerselstegn: `"zip"` slap forbi et
                // literal-match paa `'zip'` (maalt).
                if (! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                if (trim($t[1], "\"'") !== 'zip') {
                    continue;
                }

                // 🪤 Bredere end `empty|isset`: `! $p['zip']`, `=== ''`,
                // `== null` og `strlen(...) === 0` er alle samme regel skrevet
                // om. Vi ser derfor paa OMGIVELSERNE, ikke kun paa et
                // noegleord — og paa flere tokens end de oprindelige 8.
                $omkring = '';
                for ($j = max(0, $i - 14); $j < min(count($kode), $i + 8); $j++) {
                    $omkring .= is_array($kode[$j]) ? $kode[$j][1] : $kode[$j];
                }

                $erTilstandstest = preg_match(
                    '/(empty|isset|array_key_exists|strlen|!|===|==|!==|!=)/',
                    $omkring
                );

                if ($erTilstandstest) {
                    $synder[] = basename($f->getPathname()).':'.($t[2] ?? 0);
                }
            }
        }
    }

    sort($synder);
    expect($synder)->toBe([]);
});

it('praedikatet svarer det samme som chokepunkt-guarden', function () {
    $api = app(RegistryApi::class);

    foreach ([
        'Strandvejen 100 B, 2900 Hellerup',
        'Vestergade 1 A, 5000 Odense C',
        'Søndergade 43A, 4653 Karise',
    ] as $a) {
        expect($api->adresseKanOploeses($a))->toBeTrue();
    }

    foreach (['Søndergade 43A', 'Bredgade 40', ''] as $a) {
        expect($api->adresseKanOploeses($a))->toBeFalse();
    }
});
