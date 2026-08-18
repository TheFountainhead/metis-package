<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class PersonSummary extends MetisSection
{
    public array $companies = [];
    public array $properties = [];
    public array $valuations = [];

    // Aggregated figures
    public int $activeCompanyCount = 0;
    public int $historicalCompanyCount = 0;
    public int $propertyCount = 0;
    public float $totalEquityShare = 0;
    public float $totalPropertyValuation = 0;
    public float $totalPropertyDebt = 0;
    public float $estimatedNetWorth = 0;

    protected function sectionTitle(): string
    {
        return __('Overview');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $api = app(RegistryApi::class);

        // Fetch companies and properties in parallel via rescue
        $companyResult = rescue(fn () => $api->fetchCompaniesByCpr($query));
        $propertyResult = rescue(fn () => $api->fetchPropertiesByCpr($query));

        // 🚨 ÉN fejlet halvdel er nok. Sektionen viser TAL ("0 Aktive
        // selskaber", "0 Ejendomme"), og et tal er den mest overbevisende
        // falske benaegtelse: det ligner et resultat. Fejler kun det ene
        // kald, ville den anden halvdels tal se komplet ud ved siden af et
        // nul der bare betyder "vi kunne ikke spoerge".
        //
        // 🪤 Derfor `||` og ikke `&&`: at kraeve at BEGGE fejler ville skjule
        // en halv fejl bag et helt tal. Maalt foer rettelsen: et misdannet
        // 200-svar gav "0 Aktive selskaber / 0 Ejendomme" og
        // estimatedNetWorth 0,0 — uden at noget indikerede at opslaget
        // mislykkedes.
        if ($this->opslagFejlede($companyResult) || $this->opslagFejlede($propertyResult)) {
            return;
        }

        $this->companies = $companyResult['companies'] ?? [];
        $this->properties = $propertyResult['properties'] ?? [];

        $allCompanies = collect($this->companies);
        $activeCompanies = $allCompanies->filter(
            fn ($c) => ($c['status'] ?? '') === 'NORMAL' || ($c['is_active'] ?? false)
        );

        $this->activeCompanyCount = $activeCompanies->count();
        $this->historicalCompanyCount = $allCompanies->count() - $this->activeCompanyCount;
        $this->propertyCount = count($this->properties);

        // Identify subsidiary CVRs to avoid double-counting consolidated equity
        $activeCvrs = $activeCompanies->pluck('cvr')->filter()->unique()->values()->toArray();
        $subsidiaryCvrs = collect();

        if (count($activeCvrs) >= 2) {
            $crossOwnership = rescue(fn () => $api->fetchCrossOwnership($activeCvrs), []);
            foreach ($crossOwnership['relationships'] ?? [] as $rel) {
                if ($rel['child_cvr'] ?? null) {
                    $subsidiaryCvrs->push($rel['child_cvr']);
                }
            }
        }

        // Sum equity x ownership share for top-level companies only
        foreach ($activeCompanies as $company) {
            if ($subsidiaryCvrs->contains($company['cvr'])) {
                continue; // Skip: equity is consolidated in parent
            }

            $fin = $company['financials'] ?? null;
            $latestFin = is_array($fin) && array_is_list($fin) ? ($fin[0] ?? null) : $fin;
            $equity = $latestFin['equity'] ?? 0;
            $ownershipShare = collect($company['roles'] ?? [])
                ->where('is_current', true)
                ->max('ownership_share');

            if ($equity && $ownershipShare) {
                $this->totalEquityShare += ($equity / 100) * ($ownershipShare / 100);
            }
        }

        // Sum property valuations and debt
        foreach ($this->properties as $prop) {
            if (isset($prop['public_valuation'])) {
                $this->totalPropertyValuation += $prop['public_valuation'];
            }
            $this->totalPropertyDebt += collect($prop['mortgages'] ?? [])->sum('outstanding_debt');
        }

        $this->estimatedNetWorth = $this->totalEquityShare
            + $this->totalPropertyValuation
            - $this->totalPropertyDebt;

        // Detect capital increases (kapitalforhojelser) for active companies
        foreach ($activeCompanies as $company) {
            $financials = $company['financials'] ?? [];
            // Only works with indexed array of multiple years, not flat object
            if (! is_array($financials) || ! array_is_list($financials) || count($financials) < 2) {
                continue;
            }

            $valuationEvents = $this->detectCapitalIncreases($financials, $company);
            if (! empty($valuationEvents)) {
                $this->valuations = array_merge($this->valuations, $valuationEvents);
            }
        }

        // Sort valuations by year descending
        usort($this->valuations, fn ($a, $b) => ($b['year'] ?? 0) <=> ($a['year'] ?? 0));
    }

    protected function detectCapitalIncreases(array $financials, array $company): array
    {
        $events = [];

        // financials[0] is latest, financials[1] is previous, etc.
        for ($i = 0; $i < count($financials) - 1; $i++) {
            $current = $financials[$i];
            $previous = $financials[$i + 1];

            $capitalNow = $current['contributed_capital'] ?? null;
            $capitalBefore = $previous['contributed_capital'] ?? null;

            if ($capitalNow === null || $capitalBefore === null || $capitalNow <= $capitalBefore) {
                continue;
            }

            // Capital increase detected
            $capitalIncrease = $capitalNow - $capitalBefore;
            $equityNow = $current['equity'] ?? 0;
            $equityBefore = $previous['equity'] ?? 0;
            $profitLoss = $current['profit_loss'] ?? 0;

            // Capital injection = equity change minus organic profit
            $capitalInjection = ($equityNow - $equityBefore) - $profitLoss;

            // New shares as fraction of total
            $newShareFraction = $capitalIncrease / $capitalNow;

            // Implied post-money valuation
            $impliedValuation = null;
            if ($capitalInjection > 0 && $newShareFraction > 0) {
                $impliedValuation = (int) ($capitalInjection / $newShareFraction);
            }

            // Share premium (overkurs)
            $sharePremium = $capitalInjection > $capitalIncrease
                ? $capitalInjection - $capitalIncrease
                : null;

            $events[] = [
                'company_name' => $company['name'] ?? $company['cvr'],
                'cvr' => $company['cvr'],
                'year' => $current['year'] ?? $current['period_end'] ?? null,
                'capital_before' => $capitalBefore,
                'capital_after' => $capitalNow,
                'capital_increase' => $capitalIncrease,
                'capital_injection' => $capitalInjection > 0 ? $capitalInjection : null,
                'implied_valuation' => $impliedValuation,
                'share_premium' => $sharePremium,
                'equity_after' => $equityNow,
            ];
        }

        return $events;
    }

    public function render()
    {
        return view('metis::livewire.sections.person-summary');
    }
}
