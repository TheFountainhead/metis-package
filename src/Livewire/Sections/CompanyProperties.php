<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyProperties extends MetisSection
{
    public ?array $portfolio = null;
    public bool $enriching = false;
    public bool $building = false;
    public int $propertiesFound = 0;

    protected function sectionTitle(): string
    {
        return __('Property Portfolio');
    }

    public function mount(string $query): void
    {
        $this->query = $query;

        // Load existing cached data immediately
        $result = rescue(fn () => app(RegistryApi::class)
            ->fetchCompanyPropertyPortfolio($query, limit: 15));
        $this->portfolio = $result['portfolio'] ?? null;
        $this->building = (bool) data_get($result, 'portfolio.building', false);

        // Check if enrichment is running
        $status = rescue(fn () => app(RegistryApi::class)
            ->getEnrichmentStatus($query));
        $this->enriching = in_array($status['status'] ?? '', ['pending', 'running']);
        $this->propertiesFound = $status['properties_found'] ?? 0;
    }

    public function pollPortfolio(): void
    {
        $result = rescue(fn () => app(RegistryApi::class)
            ->fetchCompanyPropertyPortfolio($this->query, limit: 15));
        $portfolio = $result['portfolio'] ?? null;

        if ($portfolio && empty($portfolio['building'])) {
            $this->portfolio = $portfolio;
            $this->building = false;
        }
    }

    public function pollForUpdates(): void
    {
        if (! $this->enriching) {
            return;
        }

        $status = rescue(fn () => app(RegistryApi::class)
            ->getEnrichmentStatus($this->query));

        $newStatus = $status['status'] ?? 'completed';
        $newCount = $status['properties_found'] ?? 0;
        $isDone = in_array($newStatus, ['completed', 'failed']);

        if ($newCount > $this->propertiesFound || $isDone) {
            $limit = $isDone ? 500 : 50; // Full fetch on completion, summary during polling
            $result = rescue(fn () => app(RegistryApi::class)
                ->fetchCompanyPropertyPortfolio($this->query, limit: $limit));
            $this->portfolio = $result['portfolio'] ?? $this->portfolio;
            $this->propertiesFound = $newCount;
        }

        if ($isDone) {
            $this->enriching = false;
        }
    }

    public function loadMore(): void
    {
        if (! $this->portfolio) {
            return;
        }

        $current = count($this->portfolio['properties'] ?? []);
        $result = rescue(fn () => app(RegistryApi::class)
            ->fetchCompanyPropertyPortfolio($this->query, limit: 50, offset: $current));
        $more = $result['portfolio']['properties'] ?? [];

        if (empty($more)) {
            return;
        }

        // Reassign whole array — Livewire 3 doesn't always detect nested
        // array mutations like $this->portfolio['properties'] = ....
        $portfolio = $this->portfolio;
        $portfolio['properties'] = array_merge($portfolio['properties'] ?? [], $more);
        $portfolio['property_count'] = count($portfolio['properties']);
        $this->portfolio = $portfolio;
    }

    public function render()
    {
        return view('metis::livewire.sections.company-properties');
    }
}
