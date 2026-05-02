<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use Livewire\Attributes\Url;
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

    // Filters (Q5 + Q8 per spec lines 460-465)
    public string $status = 'active'; // active | inactive | all
    public array $mortgageTypeFilter = [];
    public ?int $minAmount = null;
    public ?int $maxAmount = null;
    public string $sortBy = 'principal_amount_desc';
    public string $treeDepthState = '1'; // '1' | 'full'

    /**
     * Drawer state — URL-bound for shareable links. Namespaced (`ting_mortgage`)
     * to avoid cross-tab collision with future drawers per spec line 615.
     */
    #[Url(as: 'ting_mortgage')]
    public ?int $openMortgageId = null;

    /**
     * Lazy-loaded change-history for the currently-open drawer mortgage.
     * Populated on demand via loadChangeHistory(). Stays null until first open.
     */
    public ?array $changeHistory = null;

    protected function sectionTitle(): string
    {
        return __('Tinglysning');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $this->fetch();
    }

    public function pollForUpdates(): void
    {
        if (! $this->streaming) {
            return;
        }

        $response = rescue(fn () => app(RegistryApi::class)
            ->fetchCompanyTinglysningOverview($this->query, $this->filters(), $this->cursor));

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
        $this->fetch();
    }

    /**
     * Livewire lifecycle hook fired when any filter property is updated.
     * Resets streaming state (mortgages, cursor) and triggers a fresh fetch.
     *
     * Note: openMortgageId is also URL-bound but is NOT a filter — exclude
     * it from the reset path so opening the drawer does not reload the table.
     */
    public function updatedStatus(): void
    {
        $this->resetAndFetch();
    }

    public function updatedMortgageTypeFilter(): void
    {
        $this->resetAndFetch();
    }

    public function updatedMinAmount(): void
    {
        $this->resetAndFetch();
    }

    public function updatedMaxAmount(): void
    {
        $this->resetAndFetch();
    }

    public function updatedSortBy(): void
    {
        $this->resetAndFetch();
    }

    public function updatedTreeDepthState(): void
    {
        $this->resetAndFetch();
    }

    public function clearFilters(): void
    {
        $this->status = 'active';
        $this->mortgageTypeFilter = [];
        $this->minAmount = null;
        $this->maxAmount = null;
        $this->sortBy = 'principal_amount_desc';
        $this->treeDepthState = '1';
        $this->resetAndFetch();
    }

    /**
     * Lazy-load change-history when drawer opens (Url-bound openMortgageId
     * change triggers this hook). Sprint 1: stub — Sprint 2 wires actual
     * F1-events endpoint.
     */
    public function updatedOpenMortgageId($value): void
    {
        if ($value === null) {
            $this->changeHistory = null;
            return;
        }

        $this->loadChangeHistory((int) $value);
    }

    public function loadChangeHistory(int $mortgageId): void
    {
        // Sprint 1 stub. Sprint 2: call registry-api F1-events endpoint
        // for this mortgage_id. Returning empty array signals "no changes
        // tracked yet" — UI shows "Ingen ændringer registreret"-state.
        $this->changeHistory = [];
    }

    /**
     * Move drawer focus to prev/next mortgage in the current list.
     * Boundary policy: clamp at first/last (no wrap-around) per spec line 587.
     */
    public function navigateDrawer(string $direction): void
    {
        if ($this->openMortgageId === null || count($this->mortgages) === 0) {
            return;
        }

        $ids = array_values(array_map(
            fn (array $m) => (int) ($m['id'] ?? 0),
            $this->mortgages,
        ));

        $currentIndex = array_search($this->openMortgageId, $ids, true);

        if ($currentIndex === false) {
            return;
        }

        $targetIndex = $direction === 'next'
            ? $currentIndex + 1
            : $currentIndex - 1;

        if ($targetIndex < 0 || $targetIndex >= count($ids)) {
            // Boundary — clamp, no wrap-around
            return;
        }

        $this->openMortgageId = $ids[$targetIndex];
    }

    /**
     * Find the currently-open mortgage in the loaded mortgages array.
     * Returns null if drawer is closed or mortgage is no longer in list
     * (filter changed since drawer opened).
     */
    public function getOpenMortgageProperty(): ?array
    {
        if ($this->openMortgageId === null) {
            return null;
        }

        foreach ($this->mortgages as $mortgage) {
            if ((int) ($mortgage['id'] ?? 0) === $this->openMortgageId) {
                return $mortgage;
            }
        }

        return null;
    }

    protected function fetch(): void
    {
        $response = rescue(fn () => app(RegistryApi::class)
            ->fetchCompanyTinglysningOverview($this->query, $this->filters()));

        if (! $response) {
            $this->hasError = true;
            $this->errorMessage = __('Kunne ikke hente tinglysningsdata. Prøv igen.');
            return;
        }

        $this->applyResponse($response);
    }

    /**
     * Reset streaming state and re-fetch with current filters. Called from
     * updatedX() lifecycle hooks when any filter changes.
     */
    protected function resetAndFetch(): void
    {
        $this->mortgages = [];
        $this->cursor = null;
        $this->streaming = false;
        $this->totalExpected = 0;
        $this->deliveredSoFar = 0;
        $this->hasError = false;
        $this->errorMessage = null;

        $this->fetch();
    }

    /**
     * Build query-param payload for RegistryApi from current filter state.
     * Converts DKK input → ører for amount range (registry-api expects ører).
     */
    protected function filters(): array
    {
        $filters = [
            'status' => $this->status,
            'sort' => $this->sortBy,
            'tree_depth' => $this->treeDepthState,
        ];

        if (! empty($this->mortgageTypeFilter)) {
            $filters['mortgage_types'] = $this->mortgageTypeFilter;
        }

        if ($this->minAmount !== null && $this->minAmount > 0) {
            $filters['min_amount'] = $this->minAmount * 100; // DKK → ører
        }

        if ($this->maxAmount !== null && $this->maxAmount > 0) {
            $filters['max_amount'] = $this->maxAmount * 100;
        }

        return $filters;
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
