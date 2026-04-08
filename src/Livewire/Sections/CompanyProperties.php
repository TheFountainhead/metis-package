<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyProperties extends MetisSection
{
    public ?array $portfolio = null;
    public bool $enriching = false;
    public int $propertiesFound = 0;
    public ?string $expandedBfe = null;
    public ?array $expandedDetails = null;

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

        // Check if enrichment is running
        $status = rescue(fn () => app(RegistryApi::class)
            ->getEnrichmentStatus($query));
        $this->enriching = in_array($status['status'] ?? '', ['pending', 'running']);
        $this->propertiesFound = $status['properties_found'] ?? 0;
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

    public function toggleProperty(string $bfe): void
    {
        if ($this->expandedBfe === $bfe) {
            $this->expandedBfe = null;
            $this->expandedDetails = null;
            return;
        }

        $this->expandedBfe = $bfe;
        $this->expandedDetails = rescue(fn () => app(RegistryApi::class)->fetchPropertyDetails($bfe));
    }

    public function loadMore(): void
    {
        $current = count($this->portfolio['properties'] ?? []);
        $result = rescue(fn () => app(RegistryApi::class)
            ->fetchCompanyPropertyPortfolio($this->query, limit: 50, offset: $current));
        $more = $result['portfolio']['properties'] ?? [];

        if ($more && $this->portfolio) {
            $this->portfolio['properties'] = array_merge(
                $this->portfolio['properties'],
                $more
            );
            $this->portfolio['property_count'] = count($this->portfolio['properties']);
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-properties');
    }
}
