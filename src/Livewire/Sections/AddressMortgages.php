<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressMortgages extends MetisSection
{
    public array $mortgages = [];
    public int $totalDebt = 0;
    public ?float $ltv = null;

    /**
     * Er ejendommens gæld overhovedet hentet fra Tinglysningen?
     *
     * 🚨 Uden dette kan vi ikke skelne "ingen gæld" fra "ikke undersøgt", og
     * sektionen skrev "No mortgages found" på begge — en falsk påstand om
     * fravær, den værste fejlmodus i kreditvurdering.
     *
     * 🪤 Målt 10/8: efter adresse-backfillen blev 1.105.049 ejendomme
     * crawl-klare på én gang, men crawlen tager ~60 døgn (Tinglysningen
     * rate-limiter til 12,3 opslag/min). I hele den periode ville u-crawlede
     * ejendomme se gældfri ud.
     */
    public bool $erUndersoegt = true;

    protected function sectionTitle(): string
    {
        return __('Mortgages');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);

        // 🚨 FOERST AF ALT. "Ingen pantebreve fundet" er en paastand om
        // GAELDFRIHED — den maa aldrig staa naar kaldet fejlede. Bladen har
        // baaret kommentaren "ville vaere en FALSK PAASTAND" siden #135, men
        // guarden daekkede kun ikke-crawlede ejendomme, ikke fejlede opslag.
        if ($this->opslagFejlede($analysis)) {
            return;
        }

        $this->mortgages = $analysis['property']['mortgages'] ?? [];
        $this->totalDebt = $analysis['property']['total_debt'] ?? 0;

        // 🪤 `array_key_exists`, ikke `?? null`. Feltet ER null naar ejendommen
        // ikke er crawlet — en null-coalesce kan ikke skelne det fra at et
        // aeldre API slet ikke sender feltet. Mangler noeglen, antager vi
        // undersoegt (bagudkompatibelt); er den til stede og null, ved vi det.
        $this->erUndersoegt = ! array_key_exists('tinglysning_synced_at', $analysis['property'] ?? [])
            || $analysis['property']['tinglysning_synced_at'] !== null;

        $estimatedValue = $analysis['property']['valuation']['estimated_value'] ?? 0;
        if ($estimatedValue > 0 && $this->totalDebt > 0) {
            $this->ltv = round($this->totalDebt / $estimatedValue * 100, 1);
        }
    }

    /**
     * Linjerne der vises, når et pantebrev foldes ud: det Tinglysningen oplyser om lånet selv
     * (registry-api `mortgages[].detaljer`). Kun linjer med indhold; tom liste = rækken er ikke klikbar.
     *
     * 🔑 Rækken er kun klikbar, når `detaljer` selv har indhold. Typen står altid på rækken i API'et,
     * så den alene må ikke gøre alle rækker klikbare (ældre API uden `detaljer`).
     *
     * @param  array<string, mixed>  $m
     * @return array<string, string>
     */
    public function detaljelinjer(array $m): array
    {
        $d = is_array($m['detaljer'] ?? null) ? $m['detaljer'] : [];
        $linjer = [];

        if (is_numeric($d['prioritet'] ?? null)) {
            $linjer[__('Prioritet')] = (string) (int) $d['prioritet'];
        }

        $tinglyst = is_string($d['tinglyst'] ?? null) ? rescue(fn () => \Illuminate\Support\Carbon::createFromFormat('!Y-m-d', $d['tinglyst']), null, false) : null;
        if ($tinglyst && $tinglyst->year > 1800) {
            $linjer[__('Tinglyst')] = $tinglyst->format('d.m.Y');
        }

        $laantyper = ['kontantlaan' => __('Kontantlån'), 'obligationslaan' => __('Obligationslån'), 'indekslaan' => __('Indekslån')];
        if (is_string($d['laantype'] ?? null) && $d['laantype'] !== '') {
            $linjer[__('Lånetype')] = $laantyper[$d['laantype']] ?? self::dansk($d['laantype']);
        }

        $ref = is_array($d['referencerente'] ?? null) ? $d['referencerente'] : null;
        if ($ref && is_string($ref['navn'] ?? null) && $ref['navn'] !== '') {
            $tillaeg = is_numeric($ref['tillaeg'] ?? null) ? (float) $ref['tillaeg'] : 0.0;
            // Tinglysningens sats uden afrunding til to decimaler: 0,125 må ikke blive 0,13, og 0,004 ikke 0,00.
            $sats = rtrim(rtrim(number_format(abs($tillaeg), 4, ',', '.'), '0'), ',');
            if (str_contains($sats, ',') && strlen(substr($sats, strpos($sats, ',') + 1)) < 2) {
                $sats .= '0';
            }
            $linjer[__('Rente')] = __('variabel').', '.$ref['navn'].($tillaeg != 0.0 ? ' '.($tillaeg < 0 ? '−' : '+').' '.$sats.' %' : '');
        } elseif (in_array($d['rentetype'] ?? null, ['fast', 'variabel'], true)) {
            $linjer[__('Rente')] = $d['rentetype'] === 'fast' ? __('fast') : __('variabel');
        }

        // Et ejerpantebrevs rente er en RAMME (juridisk maksimum), ikke den faktiske rente på et lån.
        if (($d['rente_er_ramme'] ?? false) === true && isset($linjer[__('Rente')])) {
            $linjer[__('Rente')] = __('rammerente').': '.$linjer[__('Rente')].' '.__('(juridisk maksimum, ikke den faktiske rente)');
        }

        $vilkaar = array_values(array_filter((array) ($d['saerlige_vilkaar'] ?? []), fn ($v) => is_string($v) && $v !== ''));
        if ($vilkaar) {
            $linjer[__('Særlige lånevilkår')] = implode(', ', array_map(fn ($v) => self::dansk($v), $vilkaar));
        }

        if (is_numeric($d['tinglysningsafgift_kr'] ?? null)) {
            $linjer[__('Tinglysningsafgift')] = number_format((float) $d['tinglysningsafgift_kr'], 0, ',', '.').' kr.';
        }

        $antal = is_numeric($d['ogsaa_lyst_paa'] ?? null) ? (int) $d['ogsaa_lyst_paa'] : 0;
        if ($antal > 0) {
            $linjer[__('Også lyst på')] = $antal === 1 ? __('1 anden ejendom') : __(':n andre ejendomme', ['n' => $antal]);
        }

        $respekt = is_numeric($d['respekterer'] ?? null) ? (int) $d['respekterer'] : 0;
        if ($respekt > 0) {
            $linjer[__('Respekterer')] = $respekt === 1 ? __('1 foranstående rettighed') : __(':n foranstående rettigheder', ['n' => $respekt]);
        }

        if ($linjer === []) {
            return [];
        }

        // Typen først, og kun når der er noget at folde ud. Et afgiftspantebrev er ikke gæld og må
        // ikke stå som "Ejerpantebrev" (jf. company-tinglysning).
        $typer = [
            'realkreditpantebrev' => __('Realkreditpantebrev'), 'ejerpantebrev' => __('Ejerpantebrev'),
            'privatPantebrev' => __('Privat pantebrev'), 'pantebrev' => __('Pantebrev'),
            'skadesloesbrev' => __('Skadesløsbrev'), 'udlaeg' => __('Udlæg'), 'arrest' => __('Arrest'), 'anden' => __('Anden'),
        ];
        $type = ($m['is_afgiftspantebrev'] ?? false) === true
            ? __('Afgiftspantebrev (ikke gæld)')
            : (is_string($m['type'] ?? null) && $m['type'] !== '' ? ($typer[$m['type']] ?? self::dansk($m['type'])) : null);

        return ($type !== null ? [__('Type') => $type] : []) + $linjer;
    }

    /** En kode fra Tinglysningen (ASCII-translittereret) som læsbar dansk tekst: "rentetilpasning", "afdragsfri_periode". */
    private static function dansk(string $kode): string
    {
        $tekst = str_replace(['_', 'aa', 'oe', 'ae'], [' ', 'å', 'ø', 'æ'], strtolower(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $kode)));

        return mb_strtoupper(mb_substr($tekst, 0, 1)).mb_substr($tekst, 1);
    }

    public function render()
    {
        return view('metis::livewire.sections.address-mortgages');
    }
}
