<?php

use TheFountainhead\Metis\Tests\TestCase;

pest()->extend(TestCase::class)
    ->in('Feature', 'Unit');

/*
 * 🪤 HELPEREN HOERER HJEMME HER, ikke i en testfil.
 *
 * Foerste udkast lagde den nederst i `AdresseDefinitionTest.php`, og
 * `LookupLinkEncodingTest.php` kaldte den. Pest autoloader ikke mellem
 * testfiler, saa vagten virkede kun naar BEGGE filer blev loadet sammen:
 *
 *   vendor/bin/pest tests/Feature/LookupLinkEncodingTest.php
 *     => Call to undefined function metisKodeTokens()
 *   vendor/bin/pest --parallel
 *     => 1 failed, 805 passed
 *
 * 🚨 Og den fejlede AABENT: en Error betyder at guarden slet ikke koerer.
 * CI koerer plain `pest`, saa den var groen — én `--filter` fra at vaere
 * ubrugelig.
 */
/**
 * Tokens for en PHP- ELLER blade-fil, uden kommentarer og whitespace.
 *
 * 🚨 `token_get_all()` ser en .blade.php-fil som ÉT eneste inline-HTML-token,
 * fordi Blades `@php` ikke er PHPs `<?php`. Foerste tokenizer-udkast scannede
 * derfor blades og fandt NUL — hvilket ikke er det samme som at de er rene.
 * Maalt: en haardkodet kopi i `alert-detail.blade.php` og en encoding fjernet
 * fra det RIGTIGE kaldested i `debt-search.blade.php:274` gav begge groen test.
 *
 * 🔑 Sjette variant af samme fejlklasse i denne sag — og den opstod i selve
 * rettelsen af den femte. Filtret `getExtension() === 'php'` fik blades til at
 * SE daekkede ud (extension ER 'php'), mens tokenizeren var blind indeni.
 *
 * Kuren: kompilér bladen til PHP foerst, saa `@php`-blokke og `{{ }}` bliver
 * rigtig PHP-kode tokenizeren kan laese.
 */
function metisKodeTokens(string $sti): array
{
    $kilde = file_get_contents($sti);

    if (str_ends_with($sti, '.blade.php')) {
        $kilde = app('blade.compiler')->compileString($kilde);
    }

    return array_values(array_filter(
        token_get_all($kilde),
        fn ($t) => ! is_array($t)
            || ! in_array($t[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)
    ));
}
