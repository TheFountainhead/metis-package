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
        $this->financialHistory = $this->buildFinancialHistory($info['financial_history'] ?? $info['financials'] ?? []);

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
        // 🚨 Læs API'ets total — genberegn den ALDRIG fra per-ejendoms-tal.
        //
        // Samme pantebrev kan hæfte på flere ejendomme. Målt på AKACIETORVET 31/7:
        // per ejendom 92,2 + 78,0 + 71,8 = 242,1 mio., men kun 96,1 mio. over 13
        // unikke dokumenter. Hvert enkelt tal er rigtigt; dobbelttællingen opstår
        // først når man lægger dem sammen, så visningslaget kan ikke selv opdage
        // den — den ser kun korrekte tal.
        //
        // Fallback til summen når feltet mangler (ældre cache-poster, EJF-stien):
        // et for højt tal er dårligt, men bedre end en bar streg der ser ud som
        // "ingen gæld".
        $this->totalDebt = isset($portfolio['total_debt'])
            ? (int) $portfolio['total_debt']
            : (int) collect($properties)->sum(fn ($p) => (int) ($p['total_debt'] ?? 0));
        $this->usageDistribution = $this->buildUsageDistribution($properties);
        $this->mapPins = $this->buildMapPins($properties);
    }

    /** The key figures in the development chart and table, in display order. */
    public const KEY_FIGURES = ['profit_loss', 'gross_profit', 'operating_profit', 'ebitda', 'equity', 'assets'];

    /** @return array<string, string> key figure => label, for the chart legend and the table rows */
    public function keyFigureLabels(): array
    {
        return [
            'profit_loss' => __('Result'),
            'gross_profit' => __('Gross profit'),
            'operating_profit' => __('Operating profit (EBIT)'),
            'ebitda' => 'EBITDA',
            'equity' => __('Net Equity'),
            'assets' => __('Total Assets'),
        ];
    }

    /**
     * Newest-first input → oldest-first output (chronological for the chart).
     *
     * Reads registry-api's financial_history (own years plus comparative years,
     * with EBIT/EBITDA and the period length) and falls back to the plain
     * financials list from an older registry-api. Up to six years.
     *
     * PDF years arrive in t.DKK, XBRL years in kroner (the 1000× trap the
     * company-info table also handles), so everything is normalised to kroner here.
     */
    protected function buildFinancialHistory(array $years): array
    {
        return collect($years)
            ->take(6)
            ->reverse()
            ->values()
            ->map(function (array $year) {
                $unit = ($year['source'] ?? '') === 'pdf' ? 1000 : 1;
                $months = isset($year['months']) ? (float) $year['months'] : null;

                return [
                    'year' => (string) ($year['year'] ?? ''),
                    'months' => $months,
                    'short_period' => $months !== null && $months < 11.5,
                    'comparative' => (bool) ($year['comparative'] ?? false),
                    'consolidated' => ($year['scope'] ?? null) === 'consolidated',
                    ...collect(self::KEY_FIGURES)->mapWithKeys(fn (string $key) => [
                        $key => isset($year[$key]) ? (int) $year[$key] * $unit : null,
                    ])->all(),
                ];
            })
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
