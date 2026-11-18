<?php

namespace TheFountainhead\Metis\Exports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use TheFountainhead\Metis\Livewire\Sections\AddressMortgages;

/**
 * Sampantskema og samlet pantoversigt for ÉT engagement (långiverens egne engagementer).
 *
 * Kilde: registry-api `GET /v1/engagements/{key}`:
 *   - hver række i prioritetsstigen har `document_key` (ens for samme pantebrev på flere ejendomme) og
 *     `also_registered_count` (Tinglysningens antal ØVRIGE ejendomme pantebrevet er lyst på);
 *   - engagementets `sampant` har, KUN for långiverens egne pantebreve, listen over de øvrige ejendomme
 *     (også dem uden for engagementet). For en anden kreditors pantebrev kendes kun antallet.
 *
 * 🚨 Ingen debitoroplysninger: API'et udleverer dem ikke, og skemaet har ingen kolonne til dem.
 * 🚨 Tekst fra Tinglysningen skrives som TEKST-celler (aldrig som formler): en kreditor der hedder "=..." må ikke
 *    blive til en formel i modtagerens regneark.
 */
class SampantskemaExport
{
    /**
     * @param  array<string, mixed>  $engagement  `data` fra API'et
     * @param  array<string, mixed>  $meta  `meta` fra API'et (measured_at, lender, disclaimer)
     */
    public function __construct(public readonly array $engagement, public readonly array $meta = [])
    {
        if (! class_exists(Spreadsheet::class)) {
            throw new \RuntimeException('XLSX export requires phpoffice/phpspreadsheet. Install via: composer require phpoffice/phpspreadsheet');
        }
    }

    /**
     * Pantebrevene i engagementet samlet pr. pantebrev, med de ejendomme hvert af dem er lyst på.
     * Ren funktion: bruges både af regnearket og af visningen på siden, så de aldrig er uenige.
     *
     * `properties_total` er antallet af ejendomme pantebrevet er lyst på i alt. For et eget pantebrev kendes alle
     * ejendommene; for en anden kreditors pantebrev kendes kun dem i engagementet plus antallet (`unlisted` = dem
     * vi ikke får oplyst). Et pantebrev er SAMPANT, når det er lyst på mere end én ejendom i alt.
     *
     * @param  array<string, mixed>  $engagement
     * @return list<array<string, mixed>>
     */
    public static function pantebreve(array $engagement): array
    {
        $ejendomme = array_values(array_filter(is_array($engagement['properties'] ?? null) ? $engagement['properties'] : [], 'is_array'));

        // Engagementets egne ejendomme: afgør "i engagementet", uanset om pantebrevet har en række på ejendommen.
        $iEngagementet = [];
        foreach ($ejendomme as $ejendom) {
            if (($bfe = self::tekstvaerdi($ejendom['bfe'] ?? null)) !== null) {
                $iEngagementet[$bfe] = $ejendom;
            }
        }

        $oevrige = [];
        foreach (is_array($engagement['sampant'] ?? null) ? $engagement['sampant'] : [] as $pant) {
            if (is_array($pant) && is_string($pant['document_key'] ?? null) && is_array($pant['properties'] ?? null)) {
                $oevrige[$pant['document_key']] = ['properties' => array_values(array_filter($pant['properties'], 'is_array')), 'truncated' => ($pant['truncated'] ?? false) === true];
            }
        }

        $ud = [];
        foreach ($ejendomme as $pi => $ejendom) {
            foreach (array_values(array_filter(is_array($ejendom['ladder'] ?? null) ? $ejendom['ladder'] : [], 'is_array')) as $ri => $raekke) {
                // Uden nøgle (ældre API) står hver række for sig: så bliver intet fejlagtigt slået sammen.
                $noegle = is_string($raekke['document_key'] ?? null) && $raekke['document_key'] !== '' ? $raekke['document_key'] : "raekke-{$pi}-{$ri}";

                $ud[$noegle] ??= [
                    'key' => $noegle,
                    'type' => self::typeTekst($raekke),
                    'creditor' => self::tekstvaerdi($raekke['creditor'] ?? null) ?? '',
                    'pledgees' => array_values(array_filter(array_map(fn ($p) => self::tekstvaerdi($p), (array) ($raekke['pledgees'] ?? [])))),
                    'amount_kr' => is_numeric($raekke['amount_kr'] ?? null) ? (int) $raekke['amount_kr'] : 0,
                    'registered_at' => self::tekstvaerdi($raekke['registered_at'] ?? null),
                    'is_own' => ($raekke['is_own'] ?? false) === true,
                    'others_count' => 0,
                    'truncated' => false,
                    'properties' => [],
                ];
                $ud[$noegle]['others_count'] = max($ud[$noegle]['others_count'], is_numeric($raekke['also_registered_count'] ?? null) ? (int) $raekke['also_registered_count'] : 0);

                $bfe = self::tekstvaerdi($ejendom['bfe'] ?? null) ?? "id-{$pi}";
                $ud[$noegle]['properties'][$bfe] = self::ejendom($bfe, $ejendom, true, is_numeric($raekke['priority'] ?? null) ? (int) $raekke['priority'] : null);
            }
        }

        foreach ($ud as $noegle => &$p) {
            foreach ($oevrige[$noegle]['properties'] ?? [] as $o) {
                $oBfe = self::tekstvaerdi($o['bfe'] ?? null);
                if ($oBfe === null) {
                    continue;
                }
                if (isset($p['properties'][$oBfe])) {
                    // Har allerede en række (en anden ejendom i engagementet): suppler kun matriklen.
                    $p['properties'][$oBfe]['matrikel'] ??= self::matrikel($o);

                    continue;
                }
                // En ejendom i engagementet er "i engagementet", også når dette pantebrev ingen række har på den.
                $kendt = $iEngagementet[$oBfe] ?? null;
                $p['properties'][$oBfe] = self::ejendom($oBfe, $kendt ?? $o, $kendt !== null, null);
                $p['properties'][$oBfe]['matrikel'] = self::matrikel($o);
            }
            $p['truncated'] = $oevrige[$noegle]['truncated'] ?? false;

            // Ejendomme i engagementet først, derefter efter adresse (ukendt adresse sidst) og BFE.
            uasort($p['properties'], fn ($a, $b) => [$b['in_engagement'], $a['address'] ?? "\u{10FFFF}", $a['bfe']] <=> [$a['in_engagement'], $b['address'] ?? "\u{10FFFF}", $b['bfe']]);
            $p['properties'] = array_values($p['properties']);

            // I alt: det største af det vi kan se, og denne ejendom plus Tinglysningens antal øvrige.
            $p['properties_total'] = max(count($p['properties']), 1 + $p['others_count']);
            $p['unlisted'] = $p['properties_total'] - count($p['properties']);
            $p['is_sampant'] = $p['properties_total'] > 1;
            unset($p['others_count']);
        }
        unset($p);

        // Egne pantebreve først, derefter størst hovedstol.
        usort($ud, fn ($a, $b) => [$b['is_own'], $b['amount_kr'], $a['key']] <=> [$a['is_own'], $a['amount_kr'], $b['key']]);

        return array_values($ud);
    }

    /** Kun sampant: pantebreve lyst på mere end én ejendom. */
    public static function sampant(array $engagement): array
    {
        return array_values(array_filter(self::pantebreve($engagement), fn ($p) => $p['is_sampant']));
    }

    /** @param  resource  $stream */
    public function writeTo($stream): void
    {
        $bog = new Spreadsheet;

        $this->arkSampant($bog->getActiveSheet()->setTitle('Sampantskema'));
        $this->arkPantoversigt($bog->createSheet()->setTitle('Pantoversigt'));
        $this->arkOm($bog->createSheet()->setTitle('Om'));
        $bog->setActiveSheetIndex(0);

        (new Xlsx($bog))->save($stream);
    }

    /** Én række pr. (pantebrev, ejendom) for de pantebreve der er lyst på flere ejendomme. */
    private function arkSampant(Worksheet $ark): void
    {
        $this->overskrifter($ark, ['Pantebrev nr.', 'Type', 'Kreditor', 'Panthavere', 'Hovedstol kr.', 'Tinglyst', 'Eget pant', 'Ejendom', 'Postnr. og by', 'BFE', 'Matrikel', 'Prioritet', 'I engagementet', 'Ejendomme i alt', 'Bemærkning']);

        $raekke = 2;
        foreach (self::sampant($this->engagement) as $nr => $p) {
            $bemaerkning = match (true) {
                $p['truncated'] => 'Listen over ejendomme er afkortet.',
                $p['unlisted'] > 0 && ! $p['is_own'] => "Anden kreditors pantebrev: {$p['unlisted']} øvrige ejendomme er ikke oplyst.",
                $p['unlisted'] > 0 => "{$p['unlisted']} øvrige ejendomme er ikke oplyst af Tinglysningen.",
                default => '',
            };
            foreach ($p['properties'] as $e) {
                $this->tekst($ark, 'A'.$raekke, (string) ($nr + 1));
                $this->tekst($ark, 'B'.$raekke, $p['type']);
                $this->tekst($ark, 'C'.$raekke, $p['creditor']);
                $this->tekst($ark, 'D'.$raekke, implode(', ', $p['pledgees']));
                $ark->setCellValue('E'.$raekke, $p['amount_kr']);
                $this->tekst($ark, 'F'.$raekke, $p['registered_at'] ?? '');
                $this->tekst($ark, 'G'.$raekke, $p['is_own'] ? 'Ja' : 'Nej');
                $this->tekst($ark, 'H'.$raekke, $e['address'] ?? 'Adresse ikke kendt');
                $this->tekst($ark, 'I'.$raekke, trim(($e['postal_code'] ?? '').' '.($e['city'] ?? '')));
                $this->tekst($ark, 'J'.$raekke, $e['bfe']);
                $this->tekst($ark, 'K'.$raekke, $e['matrikel'] ?? '');
                if ($e['priority'] !== null) {
                    $ark->setCellValue('L'.$raekke, $e['priority']);
                }
                $this->tekst($ark, 'M'.$raekke, $e['in_engagement'] ? 'Ja' : 'Nej');
                $ark->setCellValue('N'.$raekke, $p['properties_total']);
                $this->tekst($ark, 'O'.$raekke, $bemaerkning);
                $ark->getStyle('E'.$raekke)->getNumberFormat()->setFormatCode('#,##0');
                $raekke++;
            }
        }

        if ($raekke === 2) {
            $this->tekst($ark, 'A2', 'Ingen pantebreve i engagementet er lyst på mere end én ejendom.');
        }
        $this->autobredde($ark, 'O');
    }

    /** Samlet pantoversigt: hver ejendoms prioritetsstige, én række pr. hæftelse. */
    private function arkPantoversigt(Worksheet $ark): void
    {
        $this->overskrifter($ark, ['Ejendom', 'Postnr. og by', 'BFE', 'Prioritet', 'Type', 'Kreditor', 'Panthavere', 'Hovedstol kr.', 'Tinglyst', 'Eget pant', 'Foran eget pant', 'Sideordnet', 'Lyst på ejendomme i alt']);

        $iAlt = [];
        foreach (self::pantebreve($this->engagement) as $p) {
            $iAlt[$p['key']] = $p['properties_total'];
        }

        $raekke = 2;
        foreach (array_values(array_filter(is_array($this->engagement['properties'] ?? null) ? $this->engagement['properties'] : [], 'is_array')) as $pi => $ejendom) {
            // Kendes prioriteten på det egne pant ikke, kan "foran" ikke afgøres for NOGEN række på ejendommen
            // (API'et sætter da ahead_kr til null). "Nej" ville fortælle læseren at ingen står foran.
            $foranUkendt = array_key_exists('ahead_kr', $ejendom) && $ejendom['ahead_kr'] === null;

            foreach (array_values(array_filter(is_array($ejendom['ladder'] ?? null) ? $ejendom['ladder'] : [], 'is_array')) as $ri => $r) {
                $egen = ($r['is_own'] ?? false) === true;
                $noegle = is_string($r['document_key'] ?? null) && $r['document_key'] !== '' ? $r['document_key'] : "raekke-{$pi}-{$ri}";

                $this->tekst($ark, 'A'.$raekke, self::tekstvaerdi($ejendom['address'] ?? null) ?? '');
                $this->tekst($ark, 'B'.$raekke, trim((self::tekstvaerdi($ejendom['postal_code'] ?? null) ?? '').' '.(self::tekstvaerdi($ejendom['city'] ?? null) ?? '')));
                $this->tekst($ark, 'C'.$raekke, self::tekstvaerdi($ejendom['bfe'] ?? null) ?? '');
                if (is_numeric($r['priority'] ?? null)) {
                    $ark->setCellValue('D'.$raekke, (int) $r['priority']);
                }
                $this->tekst($ark, 'E'.$raekke, self::typeTekst($r));
                $this->tekst($ark, 'F'.$raekke, self::tekstvaerdi($r['creditor'] ?? null) ?? '');
                $this->tekst($ark, 'G'.$raekke, implode(', ', array_filter(array_map(fn ($p) => self::tekstvaerdi($p), (array) ($r['pledgees'] ?? [])))));
                $ark->setCellValue('H'.$raekke, is_numeric($r['amount_kr'] ?? null) ? (int) $r['amount_kr'] : 0);
                $this->tekst($ark, 'I'.$raekke, self::tekstvaerdi($r['registered_at'] ?? null) ?? '');
                $this->tekst($ark, 'J'.$raekke, $egen ? 'Ja' : 'Nej');
                $ukendt = ! $egen && ($foranUkendt || ($r['priority_unknown'] ?? false) === true);
                $this->tekst($ark, 'K'.$raekke, $egen ? '' : ($ukendt ? 'Ukendt' : ((($r['is_ahead'] ?? false) === true) ? 'Ja' : 'Nej')));
                $this->tekst($ark, 'L'.$raekke, $egen ? '' : ($ukendt ? 'Ukendt' : ((($r['is_pari'] ?? false) === true) ? 'Ja' : 'Nej')));
                $ark->setCellValue('M'.$raekke, $iAlt[$noegle] ?? 1);
                $ark->getStyle('H'.$raekke)->getNumberFormat()->setFormatCode('#,##0');
                $raekke++;
            }
        }
        $this->autobredde($ark, 'M');
    }

    private function arkOm(Worksheet $ark): void
    {
        $linjer = [
            ['Sampantskema og pantoversigt'],
            ['Långiver', self::tekstvaerdi($this->meta['lender']['name'] ?? null) ?? ''],
            ['Målt', self::tekstvaerdi($this->meta['measured_at'] ?? null) ?? ''],
            ['Kilde', 'Tinglysningen'],
            [''],
            [self::tekstvaerdi($this->meta['disclaimer'] ?? null) ?? ''],
            ['Hovedstol er den tinglyste hovedstol, ikke den aktuelle restgæld.'],
            ['Et ejerpantebrevs hovedstol er en ramme; den reelle gæld fremgår af panthaverne (underpant).'],
            ['For långiverens egne pantebreve vises alle de ejendomme, pantebrevet er lyst på, også uden for engagementet.'],
            ['For andre kreditorers pantebreve vises kun ejendommene i engagementet og det samlede antal.'],
            ['En ejendom uden for engagementet vises med BFE og matrikel; adressen står der, når Metis kender ejendommen.'],
        ];
        foreach ($linjer as $i => $linje) {
            foreach ($linje as $j => $vaerdi) {
                $this->tekst($ark, chr(65 + $j).($i + 1), $vaerdi);
            }
        }
        $ark->getStyle('A1')->getFont()->setBold(true);
        $this->autobredde($ark, 'B');
    }

    /** @param  list<string>  $titler */
    private function overskrifter(Worksheet $ark, array $titler): void
    {
        foreach ($titler as $i => $titel) {
            $this->tekst($ark, chr(65 + $i).'1', $titel);
        }
        $ark->getStyle('A1:'.chr(64 + count($titler)).'1')->getFont()->setBold(true);
        $ark->freezePane('A2');
    }

    private function autobredde(Worksheet $ark, string $sidste): void
    {
        foreach (range('A', $sidste) as $kolonne) {
            $ark->getColumnDimension($kolonne)->setAutoSize(true);
        }
    }

    /** Altid en tekstcelle: indhold fra kilden må aldrig blive tolket som en formel. */
    private function tekst(Worksheet $ark, string $celle, string $vaerdi): void
    {
        $ark->setCellValueExplicit($celle, $vaerdi, DataType::TYPE_STRING);
    }

    /**
     * @param  array<string, mixed>  $kilde  en ejendom i engagementet eller en post i `sampant`
     * @return array{bfe: string, address: ?string, postal_code: ?string, city: ?string, matrikel: ?string, priority: ?int, in_engagement: bool}
     */
    private static function ejendom(string $bfe, array $kilde, bool $iEngagementet, ?int $prioritet): array
    {
        return [
            'bfe' => $bfe,
            'address' => self::tekstvaerdi($kilde['address'] ?? null),
            'postal_code' => self::tekstvaerdi($kilde['postal_code'] ?? null),
            'city' => self::tekstvaerdi($kilde['city'] ?? null),
            'matrikel' => null,
            'priority' => $prioritet,
            'in_engagement' => $iEngagementet,
        ];
    }

    /** "Assens Bygrunde 292b, lejl. 3": ejerlavets navn (ellers kode), matrikelnummer og evt. ejerlejlighedsnummer. */
    private static function matrikel(array $o): ?string
    {
        $tekst = trim(implode(' ', array_filter([self::tekstvaerdi($o['ejerlav_navn'] ?? null) ?? self::tekstvaerdi($o['ejerlav'] ?? null), self::tekstvaerdi($o['matrikelnr'] ?? null)])));
        $lejlighed = self::tekstvaerdi($o['ejerlejlighed'] ?? null);
        $tekst = trim($tekst.($lejlighed !== null ? ($tekst !== '' ? ', ' : '').'lejl. '.$lejlighed : ''));

        return $tekst !== '' ? $tekst : null;
    }

    /** Samme betegnelse på siden og i regnearket. Et afgiftspantebrev hedder det, det er. */
    private static function typeTekst(array $raekke): string
    {
        if (($raekke['is_afgiftspantebrev'] ?? false) === true) {
            return 'Afgiftspantebrev';
        }
        $type = self::tekstvaerdi($raekke['type'] ?? null);

        return $type === null ? '' : AddressMortgages::dansk($type);
    }

    private static function tekstvaerdi(mixed $v): ?string
    {
        return is_scalar($v) && ! is_bool($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }
}
