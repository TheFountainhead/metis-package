<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyOverview extends MetisSection
{
    public ?string $companyName = null;
    public int $propertyCount = 0;
    public int $totalValuation = 0;
    public int $totalDebt = 0;
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

        if (! $portfolio) {
            return;
        }

        $properties = $portfolio['properties'] ?? [];
        $this->propertyCount = (int) ($portfolio['property_count'] ?? count($properties));
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
            ->filter()
            ->countBy()
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
