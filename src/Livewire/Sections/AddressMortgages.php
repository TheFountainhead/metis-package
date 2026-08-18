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

    public function render()
    {
        return view('metis::livewire.sections.address-mortgages');
    }
}
