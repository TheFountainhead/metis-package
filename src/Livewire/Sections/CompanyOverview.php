<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\BbrUsageCategory;
use TheFountainhead\Metis\Services\RegistryApi;

class CompanyOverview extends MetisSection
{
    public ?string $companyName = null;
    public int $propertyCount = 0;
    public int $totalValuation = 0;
    public int $totalDebt = 0;

    /**
     * Datadækning fra registry-api. Uden den viser KPI'en "Tinglyst gæld —",
     * og den bare streg ser ud som "ingen gæld". AKACIETORVET havde 3 af 4
     * ejendomme uundersøgt med 79,9 mio. i hæftelser bag stregen.
     *
     * @var array<string, mixed>|null
     */
    public ?array $coverage = null;
    public ?int $employees = null;

    /** @var array<string,int>  Map of building_usage label → property count */
    public array $usageDistribution = [];

    /** @var list<array{year:string,equity:?int,assets:?int,profit_loss:?int}> */
    public array $financialHistory = [];

    /** @var list<array{lat:float,lng:float,address:string}> */
    public array $mapPins = [];

    protected function sectionTitle(): string
    {
        return __('Overview');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        $info = rescue(fn () => $api->fetchCompanyInfo($query)) ?? [];
        $this->companyName = $info['name'] ?? null;
        $this->employees = isset($info['employees']) ? (int) $info['employees'] : null;
        $this->financialHistory = $this->buildFinancialHistory($info['financials'] ?? []);

        $portfolioResp = rescue(fn () => $api->fetchCompanyPropertyPortfolio($query, limit: 500));
        $portfolio = $portfolioResp['portfolio'] ?? null;

        // coverage ligger ved siden af portfolio når den er null, og inde i den
        // når der er noget at vise. Begge steder skal læses — den tomme
        // portefølje er netop den tilstand hvor forbeholdet betyder mest.
        $this->coverage = $portfolio['coverage'] ?? $portfolioResp['coverage'] ?? null;

        if (! $portfolio) {
            return;
        }

        $properties = $portfolio['properties'] ?? [];
        // total_count er det fulde antal ejendomme i porteføljen; property_count
        // er kun antallet på den hentede side (limit). Vis totalen, ellers
        // under-tæller KPI'en store porteføljer (fx JEUDAN: 649 vs. side-500).
        $this->propertyCount = (int) ($portfolio['total_count'] ?? $portfolio['property_count'] ?? count($properties));
        $this->totalValuation = (int) ($portfolio['total_valuation'] ?? 0);
        $this->totalDebt = (int) collect($properties)->sum(fn ($p) => (int) ($p['total_debt'] ?? 0));
        $this->usageDistribution = $this->buildUsageDistribution($properties);
        $this->mapPins = $this->buildMapPins($properties);
    }

    /**
     * Newest-first input → oldest-first output (chronological for the chart).
     * Keeps last 3 years only — anything further back is rarely meaningful.
     */
    protected function buildFinancialHistory(array $financials): array
    {
        return collect($financials)
            ->take(3)
            ->reverse()
            ->values()
            ->map(fn ($f) => [
                'year' => (string) ($f['year'] ?? ''),
                'equity' => isset($f['equity']) ? (int) $f['equity'] : null,
                'assets' => isset($f['assets']) ? (int) $f['assets'] : null,
                'profit_loss' => isset($f['profit_loss']) ? (int) $f['profit_loss'] : null,
            ])
            ->toArray();
    }

    protected function buildUsageDistribution(array $properties): array
    {
        return collect($properties)
            ->pluck('building_usage')
            ->map(fn ($usage) => BbrUsageCategory::label($usage))
            ->filter()
            ->countBy()
            ->sortDesc()
            ->toArray();
    }

    protected function buildMapPins(array $properties): array
    {
        return collect($properties)
            ->filter(fn ($p) => ! empty($p['latitude']) && ! empty($p['longitude']))
            ->map(fn ($p) => [
                'lat' => (float) $p['latitude'],
                'lng' => (float) $p['longitude'],
                'address' => trim(($p['address'] ?? '').', '.($p['postal_code'] ?? '').' '.($p['city'] ?? ''), ', '),
            ])
            ->values()
            ->toArray();
    }

    public function render()
    {
        return view('metis::livewire.sections.company-overview');
    }
}
