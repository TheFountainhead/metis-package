<?php

namespace TheFountainhead\Metis\Services;

/**
 * Oversætter BBR BygAnvendelse-koder til læsbare, brede kategorier til
 * ejendomstype-fordelingen (donut-diagrammet på selskabs-/personoverblikket).
 *
 * Uden dette viser diagrammet de rå BBR-koder (fx "220", "321", "412"), som
 * ingen bruger kan afkode og som splitter donuten i 30+ ministykker. Koder
 * grupperes i stedet i få hovedkategorier, så flere koder for samme anvendelse
 * lægges sammen til ét segment.
 *
 * Kode-intervaller verificeret mod den officielle BBR 2.0-kodeliste
 * (teknik.bbr.dk, BygAnvendelse). Bemærk at BBR 2.0 (fx 321=kontor,
 * 322=detailhandel, 323=lager) adskiller sig fra legacy BBR 1.0 — vi følger
 * 2.0-semantikken, da porteføljedata leveres i den.
 *
 * Returnerer STABILE, sprog-uafhængige nøgler ("Bolig", "Kontor", ...). De
 * bruges som aggregeringsnøgler (countBy), så de må ikke oversættes her;
 * oversættelse til visning sker i Blade via __() på nøglen.
 */
class BbrUsageCategory
{
    /**
     * @param  string|int|null  $usage  Rå BBR-kode ("321") ELLER en allerede
     *                                  humaniseret label ("Kontor"). Ikke-numeriske
     *                                  værdier returneres uændret (idempotent).
     */
    public static function label(string|int|null $usage): ?string
    {
        if ($usage === null || $usage === '') {
            return null;
        }

        // Kun rene heltals-koder klassificeres. Alt andet (allerede-humaniseret
        // tekst fra ældre datakilder, eller uventet junk) returneres uændret, så
        // en gyldig label står, men fx "140abc" eller "3.5" aldrig fejl-buckets.
        $digits = (string) $usage;
        if (! ctype_digit($digits)) {
            return $digits;
        }

        $code = (int) $digits;

        // Returnér STABILE, sprog-uafhængige kategori-nøgler. Aggregeringen
        // (countBy) grupperer på disse, så de må ikke oversættes her —
        // oversættelse sker i visningen (Blade) via __() på nøglen.
        return match (true) {
            // 100-199: Boligbebyggelse (parcelhus, række-, etagebolig mv.)
            $code >= 100 && $code < 200 => 'Bolig',

            // 210-219: Landbrug, gartneri, stalde, væksthuse
            $code >= 210 && $code <= 219 => 'Landbrug',

            // 220-239: Industri/produktion/værksted + energi, vand, affald/forsyning
            $code >= 220 && $code <= 239 => 'Produktion',

            // 310-319: Parkering- og transportanlæg
            $code >= 310 && $code <= 319 => 'Transport',

            // 320 (udfaset aggregat kontor/handel/lager), 321, 329: Kontor
            $code === 320 || $code === 321 || $code === 329 => 'Kontor',

            // 322, 324, 325: Detailhandel, butikscenter, tankstation
            $code === 322 || $code === 324 || $code === 325 => 'Butik',

            // 323: Lager
            $code === 323 => 'Lager',

            // 330-339: Hotel, restaurant, café og personlig service (339 = anden serviceerhverv)
            $code >= 330 && $code <= 339 => 'Hotel/service',

            // 390 + øvrig 300-serie: udfaset/andet erhverv
            $code >= 300 && $code < 400 => 'Erhverv',

            // 400-499: Kultur, institution, uddannelse, sundhed
            $code >= 400 && $code < 500 => 'Institution',

            // 500-599: Fritidsformål (sommerhus, kolonihave, sportsanlæg)
            $code >= 500 && $code < 600 => 'Fritid',

            // 900-999: Garage, carport, udhus, ukendt
            $code >= 900 && $code < 1000 => 'Andet',

            default => 'Andet',
        };
    }
}
