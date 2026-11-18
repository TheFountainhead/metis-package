<?php

namespace TheFountainhead\Metis\Exports;

use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Sampantskema og samlet pantoversigt for ÉT engagement (långiverens egne engagementer).
 *
 * Kilde: registry-api `GET /v1/engagements/{key}`, hvor hver række i prioritetsstigen har `document_key`
 * (ens for samme pantebrev på flere ejendomme) og `also_registered_on` (de øvrige ejendomme pantebrevet er lyst på,
 * også dem uden for engagementet).
 *
 * 🚨 Ingen debitoroplysninger: API'et udleverer dem ikke, og skemaet har ingen kolonne til dem.
 * 🚨 Tekst fra Tinglysningen skrives som TEKST-celler (aldrig som formler): en kreditor der hedder "=..." må ikke
 *    blive til en formel i modtagerens regneark.
 */
class SampantskemaExport
{
    /**
     * @param  array<string, mixed>  $engagement  `data` fra API'et
     * @param  array<string, mixed>  $meta  `meta` fra API'et (measured_at, lender)
     */
    public function __construct(public readonly array $engagement, public readonly array $meta = [])
    {
        if (! class_exists(Spreadsheet::class)) {
            throw new \RuntimeException('XLSX export requires phpoffice/phpspreadsheet. Install via: composer require phpoffice/phpspreadsheet');
        }
    }

    /**
     * Pantebrevene i engagementet samlet pr. pantebrev, med alle de ejendomme hvert af dem er lyst på.
     * Ren funktion: bruges både af regnearket og af visningen på siden, så de aldrig er uenige.
     *
     * Et pantebrev er SAMPANT, når det er lyst på mere end én ejendom i alt (i eller uden for engagementet).
     *
     * @param  array<string, mixed>  $engagement
     * @return list<array{key: string, type: string, creditor: string, pledgees: list<string>, amount_kr: int, registered_at: ?string, is_own: bool, is_sampant: bool, properties: list<array{bfe: string, address: ?string, postal_code: ?string, city: ?string, matrikel: ?string, priority: ?int, in_engagement: bool}>}>
     */
    public static function pantebreve(array $engagement): array
    {
        $ud = [];

        foreach (is_array($engagement['properties'] ?? null) ? $engagement['properties'] : [] as $ejendom) {
            if (! is_array($ejendom)) {
                continue;
            }
            foreach (is_array($ejendom['ladder'] ?? null) ? $ejendom['ladder'] : [] as $raekke) {
                if (! is_array($raekke)) {
                    continue;
                }
                // Uden nøgle (ældre API) står hver række for sig: så bliver intet fejlagtigt slået sammen.
                $noegle = is_string($raekke['document_key'] ?? null) && $raekke['document_key'] !== ''
                    ? $raekke['document_key']
                    : 'raekke-'.($raekke['id'] ?? spl_object_id((object) $raekke));

                $ud[$noegle] ??= [
                    'key' => $noegle,
                    'type' => self::tekstvaerdi($raekke['type'] ?? null) ?? '',
                    'creditor' => self::tekstvaerdi($raekke['creditor'] ?? null) ?? '',
                    'pledgees' => array_values(array_filter(array_map(fn ($p) => self::tekstvaerdi($p), (array) ($raekke['pledgees'] ?? [])))),
                    'amount_kr' => is_numeric($raekke['amount_kr'] ?? null) ? (int) $raekke['amount_kr'] : 0,
                    'registered_at' => self::tekstvaerdi($raekke['registered_at'] ?? null),
                    'is_own' => ($raekke['is_own'] ?? false) === true,
                    'is_sampant' => false,
                    'properties' => [],
                ];

                $bfe = self::tekstvaerdi($ejendom['bfe'] ?? null) ?? ('id-'.($ejendom['id'] ?? '?'));
                // Ejendommen i engagementet: den vinder over en "øvrig ejendom"-post for samme BFE.
                $ud[$noegle]['properties'][$bfe] = [
                    'bfe' => $bfe,
                    'address' => self::tekstvaerdi($ejendom['address'] ?? null),
                    'postal_code' => self::tekstvaerdi($ejendom['postal_code'] ?? null),
                    'city' => self::tekstvaerdi($ejendom['city'] ?? null),
                    'matrikel' => $ud[$noegle]['properties'][$bfe]['matrikel'] ?? null,
                    'priority' => is_numeric($raekke['priority'] ?? null) ? (int) $raekke['priority'] : null,
                    'in_engagement' => true,
                ];

                foreach (is_array($raekke['also_registered_on'] ?? null) ? $raekke['also_registered_on'] : [] as $oevrig) {
                    $oBfe = is_array($oevrig) ? self::tekstvaerdi($oevrig['bfe'] ?? null) : null;
                    if ($oBfe === null) {
                        continue;
                    }
                    $matrikel = trim(implode(' ', array_filter([self::tekstvaerdi($oevrig['ejerlav'] ?? null), self::tekstvaerdi($oevrig['matrikelnr'] ?? null)])));
                    if (isset($ud[$noegle]['properties'][$oBfe])) {
                        // Kendt i forvejen (typisk en anden ejendom i engagementet): suppler kun matriklen.
                        $ud[$noegle]['properties'][$oBfe]['matrikel'] ??= $matrikel !== '' ? $matrikel : null;

                        continue;
                    }
                    $ud[$noegle]['properties'][$oBfe] = [
                        'bfe' => $oBfe,
                        'address' => self::tekstvaerdi($oevrig['address'] ?? null),
                        'postal_code' => self::tekstvaerdi($oevrig['postal_code'] ?? null),
                        'city' => self::tekstvaerdi($oevrig['city'] ?? null),
                        'matrikel' => $matrikel !== '' ? $matrikel : null,
                        'priority' => null,
                        'in_engagement' => false,
                    ];
                }
            }
        }

        foreach ($ud as &$p) {
            // Ejendomme i engagementet først, derefter efter adresse/BFE.
            uasort($p['properties'], fn ($a, $b) => [$b['in_engagement'], $a['address'] ?? '~', $a['bfe']] <=> [$a['in_engagement'], $b['address'] ?? '~', $b['bfe']]);
            $p['properties'] = array_values($p['properties']);
            $p['is_sampant'] = count($p['properties']) > 1;
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
        $this->overskrifter($ark, ['Pantebrev nr.', 'Type', 'Kreditor', 'Panthavere', 'Hovedstol kr.', 'Tinglyst', 'Eget pant', 'Ejendom', 'Postnr. og by', 'BFE', 'Matrikel', 'Prioritet', 'I engagementet', 'Ejendomme i alt']);

        $raekke = 2;
        foreach (self::sampant($this->engagement) as $nr => $p) {
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
                $ark->setCellValue('N'.$raekke, count($p['properties']));
                $ark->getStyle('E'.$raekke)->getNumberFormat()->setFormatCode('#,##0');
                $raekke++;
            }
        }

        if ($raekke === 2) {
            $this->tekst($ark, 'A2', 'Ingen pantebreve i engagementet er lyst på mere end én ejendom.');
        }
        $this->autobredde($ark, 'N');
    }

    /** Samlet pantoversigt: hver ejendoms prioritetsstige, én række pr. hæftelse. */
    private function arkPantoversigt(Worksheet $ark): void
    {
        $this->overskrifter($ark, ['Ejendom', 'Postnr. og by', 'BFE', 'Prioritet', 'Type', 'Kreditor', 'Panthavere', 'Hovedstol kr.', 'Tinglyst', 'Eget pant', 'Foran eget pant', 'Sideordnet', 'Lyst på ejendomme i alt']);

        $antal = [];
        foreach (self::pantebreve($this->engagement) as $p) {
            $antal[$p['key']] = count($p['properties']);
        }

        $raekke = 2;
        foreach (is_array($this->engagement['properties'] ?? null) ? $this->engagement['properties'] : [] as $ejendom) {
            foreach (is_array($ejendom['ladder'] ?? null) ? $ejendom['ladder'] : [] as $r) {
                $this->tekst($ark, 'A'.$raekke, self::tekstvaerdi($ejendom['address'] ?? null) ?? '');
                $this->tekst($ark, 'B'.$raekke, trim((self::tekstvaerdi($ejendom['postal_code'] ?? null) ?? '').' '.(self::tekstvaerdi($ejendom['city'] ?? null) ?? '')));
                $this->tekst($ark, 'C'.$raekke, self::tekstvaerdi($ejendom['bfe'] ?? null) ?? '');
                if (is_numeric($r['priority'] ?? null)) {
                    $ark->setCellValue('D'.$raekke, (int) $r['priority']);
                }
                $this->tekst($ark, 'E'.$raekke, self::tekstvaerdi($r['type'] ?? null) ?? '');
                $this->tekst($ark, 'F'.$raekke, self::tekstvaerdi($r['creditor'] ?? null) ?? '');
                $this->tekst($ark, 'G'.$raekke, implode(', ', array_filter(array_map(fn ($p) => self::tekstvaerdi($p), (array) ($r['pledgees'] ?? [])))));
                $ark->setCellValue('H'.$raekke, is_numeric($r['amount_kr'] ?? null) ? (int) $r['amount_kr'] : 0);
                $this->tekst($ark, 'I'.$raekke, self::tekstvaerdi($r['registered_at'] ?? null) ?? '');
                $this->tekst($ark, 'J'.$raekke, ($r['is_own'] ?? false) === true ? 'Ja' : 'Nej');
                $this->tekst($ark, 'K'.$raekke, ($r['priority_unknown'] ?? false) === true ? 'Ukendt' : ((($r['is_ahead'] ?? false) === true) ? 'Ja' : 'Nej'));
                $this->tekst($ark, 'L'.$raekke, ($r['is_pari'] ?? false) === true ? 'Ja' : 'Nej');
                $ark->setCellValue('M'.$raekke, $antal[$r['document_key'] ?? ''] ?? 1);
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
            ['Hovedstol er den tinglyste hovedstol, ikke den aktuelle restgæld.'],
            ['Et ejerpantebrevs hovedstol er en ramme; den reelle gæld fremgår af panthaverne (underpant).'],
            ['En ejendom uden for engagementet vises med BFE og matrikel; adressen står der, når Metis kender ejendommen.'],
            ['Listen over øvrige ejendomme er den, Tinglysningen oplyser på hæftelsen.'],
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

    private static function tekstvaerdi(mixed $v): ?string
    {
        return is_scalar($v) && trim((string) $v) !== '' ? trim((string) $v) : null;
    }
}
