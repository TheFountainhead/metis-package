<?php

namespace TheFountainhead\Metis\Services;

/**
 * Gør et VERSAL-selskabsnavn læsbart uden at ødelægge selskabsformen.
 *
 * Navne fra CVR og Tinglysningen står i VERSALER — `LegalUnitName` er pinnet
 * som API-kontrakt i registry-api's CompanyTinglysningOverviewTest:492. Vises
 * de uændret, råber siden ad brugeren.
 *
 * 🪤 mb_convert_case() laver ApS → Aps, A/S → A/s og I/S → I/s. Selskabsformen
 * er en juridisk betegnelse og skal derfor rettes tilbage bagefter.
 *
 * Samlet ét sted, fordi den samme långiver ellers står forskelligt to steder på
 * SAMME side: målt før samlingen stod Ringkjøbing Landbobank i VERSALER i
 * ejerstruktur-grafen og i normal skrift i panthaver-tabellen.
 *
 * Reglen matcher registry-api's BuildDebtReport::legalName(), så skærmen og
 * den genererede gældsrapport skriver långiveren ens. De to lever i hver sit
 * repo og kan derfor ikke dele kode — ændres reglen her, skal den følges ad.
 */
class LegalName
{
    /** Selskabsformer mb_convert_case() ødelægger, med den korrekte skrivemåde. */
    private const FORMS = [
        'Aps' => 'ApS',
        'A/s' => 'A/S',
        'I/s' => 'I/S',
        'P/s' => 'P/S',
        'K/s' => 'K/S',
        'Amba' => 'AmbA',
        'Fmba' => 'FmbA',
        'Smba' => 'SmbA',
    ];

    public static function format(string $name): string
    {
        $formatted = mb_convert_case($name, MB_CASE_TITLE, 'UTF-8');

        foreach (self::FORMS as $wrong => $right) {
            if (str_ends_with($formatted, ' '.$wrong) || $formatted === $wrong) {
                $formatted = mb_substr($formatted, 0, mb_strlen($formatted) - mb_strlen($wrong)).$right;
            }
        }

        return $formatted;
    }
}
