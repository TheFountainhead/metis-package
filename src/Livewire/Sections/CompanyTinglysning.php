<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class CompanyTinglysning extends MetisSection
{
    public ?array $company = null;
    public ?array $treeMeta = null;
    public array $tierBreakdown = [];
    public array $mortgages = [];
    public bool $streaming = false;
    public ?string $cursor = null;
    public int $totalExpected = 0;
    public int $deliveredSoFar = 0;

    protected function sectionTitle(): string
    {
        return __('Tinglysning');
    }

    public function mount(string $query): void
    {
        $this->query = $query;

        $response = rescue(fn () => app(RegistryApi::class)
            ->fetchCompanyTinglysningOverview($query));

        if (! $response) {
            $this->hasError = true;
            $this->errorMessage = __('Kunne ikke hente tinglysningsdata. Prøv igen.');
            return;
        }

        $this->applyResponse($response);
    }

    public function pollForUpdates(): void
    {
        if (! $this->streaming) {
            return;
        }

        $response = rescue(fn () => app(RegistryApi::class)
            ->fetchCompanyTinglysningOverview($this->query, [], $this->cursor));

        if (! $response) {
            // Transport blip during streaming — keep current state, retry next tick.
            return;
        }

        $this->applyResponse($response, append: true);
    }

    public function retry(): void
    {
        $this->hasError = false;
        $this->errorMessage = null;
        $this->mount($this->query);
    }

    protected function applyResponse(array $response, bool $append = false): void
    {
        $this->company = $response['company'] ?? $this->company;
        $this->treeMeta = $response['tree_meta'] ?? $this->treeMeta;
        $this->tierBreakdown = $response['tier_breakdown'] ?? $this->tierBreakdown;

        $newMortgages = $response['mortgages_added'] ?? [];
        $this->mortgages = $append
            ? array_merge($this->mortgages, $newMortgages)
            : $newMortgages;

        $streamingMeta = $response['streaming'] ?? [];
        $complete = $streamingMeta['complete'] ?? true;
        $this->streaming = ! $complete;
        $this->cursor = $streamingMeta['cursor'] ?? null;
        $this->totalExpected = (int) ($streamingMeta['total_expected'] ?? count($this->mortgages));
        $this->deliveredSoFar = (int) ($streamingMeta['delivered_so_far'] ?? count($this->mortgages));
    }

    public function render()
    {
        return view('metis::livewire.sections.company-tinglysning');
    }
}
