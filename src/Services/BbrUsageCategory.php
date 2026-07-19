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

        // Allerede en tekst-label (fx fra ældre datakilder) — lad den stå.
        if (! is_numeric($usage)) {
            return (string) $usage;
        }

        $code = (int) $usage;

        return match (true) {
            // 100-199: Boligbebyggelse (parcelhus, række-, etagebolig mv.)
            $code >= 100 && $code < 200 => __('Bolig'),

            // 210-219: Landbrug, gartneri, stalde, væksthuse
            $code >= 210 && $code <= 219 => __('Landbrug'),

            // 220-229: Industri/produktion/værksted
            $code >= 220 && $code <= 229 => __('Produktion'),

            // 310-319: Parkering- og transportanlæg
            $code >= 310 && $code <= 319 => __('Transport'),

            // 321, 329: Kontor
            $code === 321 || $code === 329 => __('Kontor'),

            // 322, 324, 325: Detailhandel, butikscenter, tankstation
            $code === 322 || $code === 324 || $code === 325 => __('Butik'),

            // 323: Lager
            $code === 323 => __('Lager'),

            // 330-339: Hotel, restaurant, café og personlig service
            $code >= 330 && $code <= 339 => __('Hotel/service'),

            // 320, 390 + øvrig 300-serie: udfaset/andet erhverv
            $code >= 300 && $code < 400 => __('Erhverv'),

            // 400-499: Kultur, institution, uddannelse, sundhed
            $code >= 400 && $code < 500 => __('Institution'),

            // 500-599: Fritidsformål (sommerhus, kolonihave, sportsanlæg)
            $code >= 500 && $code < 600 => __('Fritid'),

            // 900-999: Garage, carport, udhus, ukendt
            $code >= 900 && $code < 1000 => __('Andet'),

            default => __('Andet'),
        };
    }
}
