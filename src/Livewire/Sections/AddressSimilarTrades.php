<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

/**
 * F6: Lignende handler — Resight-paritet.
 *
 * Viser komparable salgshandler for ejendommen på adresse-siden.
 */
class AddressSimilarTrades extends MetisSection
{
    public ?array $subject = null;

    public array $similar = [];

    public int $limit = 10;

    public int $totalCount = 0;

    public int $radiusKm = 2;

    public int $areaPct = 20;

    public int $monthsBack = 24;

    protected function sectionTitle(): string
    {
        return __('Lignende handler');
    }

    public function mount(string $query): void
    {
        $this->query = $query;

        // Resolve query → BFE via address analysis. BFE-strengen hedder
        // `matrikel_id` i payloaden — `matrikel` er hele parcel-objektet.
        // 🪤 INTET `rescue()` HER. Det stod her foer, og det gjorde guarden
        // nedenfor virkningsloes: rescue() giver `null` naar kaldet kaster, og
        // opslagFejlede(null) returnerer false (den kraever et array med en
        // 'error'-noegle). Sektionen skrev da "Ingen lignende handler fundet i
        // omraadet" paa et opslag der aldrig lykkedes — det ENESTE sted den
        // falske benaegtelse overlevede denne PR.
        // De elleve oevrige sektioner har ikke rescue() og er aerligere for
        // det: en exception naar frem til Livewires egen fejlhaandtering
        // frem for at blive til en tom liste. Review-fund 18/8.
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);

        // 🚨 Et fejlet opslag maa ikke rendere som "ingen data".
        // Se MetisSection::opslagFejlede().
        if ($this->opslagFejlede($analysis)) {
            return;
        }

        $bfe = $analysis['property']['matrikel_id'] ?? null;

        if (! is_string($bfe) || $bfe === '') {
            return;
        }

        $result = rescue(fn () => app(RegistryApi::class)->fetchSimilarSales($bfe, [
            'radius_km' => $this->radiusKm,
            'area_pct' => $this->areaPct,
            'months_back' => $this->monthsBack,
        ]));

        if (! $result) {
            return;
        }

        $this->subject = $result['subject'] ?? null;
        $this->similar = $result['similar'] ?? [];
        $this->totalCount = $result['count'] ?? count($this->similar);
    }

    public function showMore(): void
    {
        $this->limit = 50;
    }

    public function render()
    {
        return view('metis::livewire.sections.address-similar-trades');
    }
}
