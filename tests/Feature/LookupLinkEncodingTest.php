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

            $tokens = array_values(array_filter(
                token_get_all(file_get_contents($f->getPathname())),
                fn ($t) => ! is_array($t)
                    || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)
            ));

            foreach ($tokens as $i => $t) {
                // Find rutenavnet som en STRENG i kode — uanset anfoerselstegn.
                if (! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING) {
                    continue;
                }

                if (trim($t[1], '"\'') !== 'metis.lookup') {
                    continue;
                }

                // Saml de naeste ~40 tokens: hele route()-kaldets argumentliste,
                // uanset hvor mange linjer den straekker sig over.
                $efter = '';
                for ($j = $i; $j < min(count($tokens), $i + 40); $j++) {
                    $efter .= is_array($tokens[$j]) ? $tokens[$j][1] : $tokens[$j];
                }

                // 🪤 BEGGE anfoerselstegn: foerste udkast matchede kun 'query',
                // saa "query" slap igennem — maalt ved mutation.
                if (! preg_match('/["\']query["\']\s*=>/', $efter)) {
                    continue;
                }

                // Kraev rawurlencode PAA query-vaerdien — ikke bare et sted i kaldet.
                if (! preg_match('/["\']query["\']\s*=>\s*rawurlencode\(/', $efter)) {
                    $synder[] = str_replace([$rod.'/', dirname($rod).'/'], '', $f->getPathname()).':'.$t[2];
                }
            }
        }
    }

    sort($synder);
    expect($synder)->toBe([]);
});
