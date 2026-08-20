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

            $tokens = token_get_all(file_get_contents($f->getPathname()));

            // Fjern alt der ikke er kode: kommentarer, docblocks, whitespace.
            $kode = array_values(array_filter($tokens, function ($t) {
                return ! is_array($t)
                    || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true);
            }));

            $iPraedikat = false;

            foreach ($kode as $i => $t) {
                // Marker praedikatets egen krop som undtaget.
                // Begge praedikat-kroppe er undtaget — de ER definitionen.
                if (is_array($t) && $t[0] === T_STRING
                    && in_array($t[1], ['adresseKanOploeses', 'parsetAdresseKanOploeses'], true)) {
                    $iPraedikat = true;
                }

                // Naeste funktionsnavn afslutter undtagelsen.
                if (is_array($t) && $t[0] === T_STRING
                    && in_array($t[1], ['parseAddress', 'fetchProperty', 'post'], true)) {
                    $iPraedikat = false;
                }

                // Moenstret vi jager: en 'zip'-opslagsnoegle i kode.
                if (! is_array($t) || $t[0] !== T_CONSTANT_ENCAPSED_STRING || $t[1] !== "'zip'") {
                    continue;
                }

                // Er den brugt i en TILSTANDS-test (empty/!/if), eller blot
                // laest som en vaerdi? Kun det foerste er en kopi af reglen.
                $foran = '';
                for ($j = max(0, $i - 8); $j < $i; $j++) {
                    $foran .= is_array($kode[$j]) ? $kode[$j][1] : $kode[$j];
                }

                if ($iPraedikat) {
                    continue;
                }

                if (str_contains($foran, 'empty') || str_contains($foran, 'isset')) {
                    $linje = is_array($t) ? $t[2] : 0;
                    $synder[] = basename($f->getPathname()).':'.$linje;
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
