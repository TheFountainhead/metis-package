<?php

namespace TheFountainhead\Metis\Services;

/**
 * Land og enhedstype for en udenlandsk ejer, som tekst til skærmen.
 *
 * ERST's udbud, Bilag 1, krav 1.5: "Udenlandsk kapital: land + enhedstype for
 * enhver grænseoverskridende investering". registry-api leverer landet som
 * ISO 3166-1 alpha-2 og enhedstypen som CVR's rå enhedstype; her bliver de til
 * dansk tekst.
 *
 * 🪤 Landenavne slås først op i den faste tabel nedenfor, så de lande vi faktisk
 * ser (målt i registry-api 5/9-2026: GB 19 · US 18 · NL 14 · DE 11 · LU 8 · CH 7
 * · SE 6 · FR 5 · KY 3) altid får et dansk navn, også på en server uden
 * intl-udvidelsen. Øvrige lande går via intl, og uden intl vises koden.
 */
class ForeignOwner
{
    protected const COUNTRIES = [
        'AT' => 'Østrig',
        'AU' => 'Australien',
        'BE' => 'Belgien',
        'BM' => 'Bermuda',
        'CA' => 'Canada',
        'CH' => 'Schweiz',
        'CN' => 'Kina',
        'CY' => 'Cypern',
        'DE' => 'Tyskland',
        'EE' => 'Estland',
        'ES' => 'Spanien',
        'FI' => 'Finland',
        'FO' => 'Færøerne',
        'FR' => 'Frankrig',
        'GB' => 'Storbritannien',
        'GG' => 'Guernsey',
        'GL' => 'Grønland',
        'HK' => 'Hongkong',
        'IE' => 'Irland',
        'IL' => 'Israel',
        'IS' => 'Island',
        'IT' => 'Italien',
        'JE' => 'Jersey',
        'JP' => 'Japan',
        'KY' => 'Caymanøerne',
        'LI' => 'Liechtenstein',
        'LT' => 'Litauen',
        'LU' => 'Luxembourg',
        'LV' => 'Letland',
        'MT' => 'Malta',
        'NL' => 'Nederlandene',
        'NO' => 'Norge',
        'PL' => 'Polen',
        'PT' => 'Portugal',
        'SE' => 'Sverige',
        'SG' => 'Singapore',
        'US' => 'USA',
        'VG' => 'De Britiske Jomfruøer',
    ];

    /**
     * CVR's enhedstype. ANDEN_DELTAGER er en deltager, der hverken er en
     * person eller en dansk CVR-virksomhed; for udenlandske ejere er det
     * selskaber og fonde (målt i registry-api 5/9-2026: 98 ANDEN_DELTAGER mod
     * 12 PERSON).
     */
    protected const UNIT_TYPES = [
        'ANDEN_DELTAGER' => 'selskab/fond',
        'VIRKSOMHED' => 'virksomhed',
        'PERSON' => 'person',
    ];

    public static function countryName(?string $code): ?string
    {
        if ($code === null || trim($code) === '') {
            return null;
        }

        $code = strtoupper(trim($code));

        if (isset(self::COUNTRIES[$code])) {
            return self::COUNTRIES[$code];
        }

        if (class_exists(\Locale::class)) {
            $name = \Locale::getDisplayRegion('-'.$code, 'da');

            // intl returnerer koden selv for en ukendt region.
            if (is_string($name) && $name !== '' && $name !== $code) {
                return $name;
            }
        }

        return $code;
    }

    public static function unitTypeLabel(?string $unitType): ?string
    {
        if ($unitType === null || trim($unitType) === '') {
            return null;
        }

        return self::UNIT_TYPES[strtoupper($unitType)]
            ?? mb_strtolower(str_replace('_', ' ', $unitType));
    }

    /**
     * "Sverige · selskab/fond". Null når hverken land eller enhedstype kendes.
     */
    public static function label(?string $country, ?string $unitType): ?string
    {
        $parts = array_filter([self::countryName($country), self::unitTypeLabel($unitType)]);

        return $parts === [] ? null : implode(' · ', $parts);
    }
}
