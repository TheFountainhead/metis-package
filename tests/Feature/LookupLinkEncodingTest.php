<?php

/*
 * 🚨 ALLE metis.lookup-links skal rawurlencode'e deres QUERY.
 *
 * `route()` lader '/', '?' og '#' staa raat i et sti-segment. En vaerdi som
 * '../../../admin' bliver til /lookup/address/../../../admin, som browseren
 * normaliserer VAEK fra /lookup/address/ foer afsendelse. Kilderne er ikke
 * vores: registry-api-svar og brugerens egen soegehistorik.
 *
 * 🪤 TRE UDKAST VAR SELV BLINDE — hver gang fordi soegefeltet var SMALLERE
 * end det der skulle bevogtes:
 *   1. scannede kun blades ⇒ fem PHP-kaldesteder usynlige (heraf to med
 *      brugerstyret input).
 *   2. tjekkede kun at ORDET stod paa linjen ⇒
 *      `['type' => rawurlencode($t), 'query' => $raa]` passerede.
 *   3. matchede kun ÉN LINJE ⇒ review omskrev et rigtigt kaldested over tre
 *      linjer UDEN encoding, og testen forblev GROEN. Maalt, ikke gaettet.
 *
 * ⇒ Vi bruger nu PHPs tokenizer. Den ser kun kode (ikke kommentarer/strenge),
 * og den er ligeglad med linjeombrydning, anfoerselstegn og mellemrum —
 * fordi den laeser TOKENS, ikke tekst.
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
            if ($f->getExtension() !== 'php') {
                continue;
            }

            // MetisLink er DEN kanoniske builder og encoder korrekt (:87).
            if (str_contains($f->getPathname(), 'MetisLink.php')) {
                continue;
            }

            $tokens = metisKodeTokens($f->getPathname());

            foreach ($tokens as $i => $t) {
                // Find rutenavnet som en STRENG i kode — uanset anfoerselstegn.
                if (! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                if (trim($t[1], '"\'') !== 'metis.lookup') {
                    continue;
                }

                // 🪤 HELE kaldet, ikke et vindue paa 40 tokens. Foerste
                // udkast missede en `'query'`-noegle der laa laengere ude i en
                // lang array — et nyt afgraenset soegefelt, samme form som det
                // gamle linje-baserede. Vi laeser nu til den matchende `)`.
                $efter = '';
                $dybde = 0;
                $set = false;
                for ($j = $i; $j < count($tokens); $j++) {
                    $tekst = is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                    $efter .= $tekst;

                    if ($tekst === '(') {
                        $dybde++;
                        $set = true;
                    } elseif ($tekst === ')') {
                        $dybde--;
                        if ($set && $dybde <= 0) {
                            break;
                        }
                    }
                }

                // 🚨 POSITIONELLE parametre: `route('metis.lookup', ['address', $q])`
                // binder {type}/{query} og er fuldt gyldigt — men har ingen
                // 'query'-noegle at matche paa. Enhver form UDEN en eksplicit
                // navngivet, encodet query er derfor en synder.
                $harNavngivetQuery = preg_match('/["\']query["\']\s*=>/', $efter);
                // 🪤 KENDT HUL, bevidst efterladt: dette beviser at et
                // rawurlencode-kald STARTER vaerdien — ikke at det DAEKKER
                // den. `'query' => rawurlencode($a) . $q` slipper igennem
                // (maalt). At jage den med mønstermatch ville kraeve et endnu
                // smallere soegefelt, og det er praecis fejlklassen denne sag
                // handler om.
                //
                // 🔑 Den varige kur er ikke en skarpere scanner, men at
                // fjerne behovet: rut alle links gennem `<x-metis-link>`
                // (MetisLink.php:87), som ikke KAN kaldes forkert. Denne test
                // er en stilladsering indtil da, ikke en loesning.
                $harEncodetQuery = preg_match('/["\']query["\']\s*=>\s*rawurlencode\s*\(/', $efter);

                // 🪤 Et redirect uden parametre (kun rutenavn) er harmloest.
                $harParametre = preg_match('/,\s*\[/', $efter) || preg_match('/,\s*\$/', $efter);

                if (! $harParametre) {
                    continue;
                }

                if (! $harNavngivetQuery || ! $harEncodetQuery) {
                    $synder[] = str_replace([$rod.'/', dirname($rod).'/'], '', $f->getPathname()).':'.$t[2];
                }
            }
        }
    }

    sort($synder);
    expect($synder)->toBe([]);
});
