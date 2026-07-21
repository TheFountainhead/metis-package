<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * "Udforsk" — geography-driven property search/segmentation (Phase 1).
 *
 * GEOGRAPHY-DRIVEN by design: a geo filter (postal-code range or municipality)
 * is REQUIRED before the API is called — refine filters (valuation/year/debt)
 * never drive alone (backend would 422; a full scan on 1.85M would be too slow).
 *
 * MVP geo = postal-code range + municipality (97%/62% data coverage, works
 * immediately). Interactive polygon map-drawing is a follow-up — the backend
 * already supports polygon (tested), the frontend map-draw integration is not
 * yet built (MapPanel is a Leaflet viewer, not a draw tool).
 *
 * Property_type and area filters are NOT offered: 0% data coverage on prod.
 */
class PropertyExplore extends Component
{
    // Geo (at least one required)
    public ?string $postalCodeFrom = null;
    public ?string $postalCodeTo = null;
    public ?string $municipalityCode = null;

    // Refine (optional, only meaningful on top of geo)
    public ?int $valuationMin = null;
    public ?int $valuationMax = null;
    public ?int $yearBuiltFrom = null;
    public ?int $yearBuiltTo = null;
    public bool $hasDebt = false;

    /** id_asc|id_desc|year_asc|year_desc */
    public string $sort = 'id_asc';

    public ?array $response = null;
    public ?string $cursor = null;

    /** Forward-only server cursor; back-navigation via remembered path. */
    public array $cursorHistory = [];

    public bool $hasSearched = false;
    public bool $loading = false;
    public ?string $error = null;
    public bool $quotaExceeded = false;
    public bool $missingGeo = false;

    protected $queryString = [
        'postalCodeFrom' => ['as' => 'postal_from'],
        'postalCodeTo' => ['as' => 'postal_to'],
        'municipalityCode' => ['as' => 'municipality'],
        'valuationMin' => ['as' => 'val_min'],
        'valuationMax' => ['as' => 'val_max'],
        'yearBuiltFrom' => ['as' => 'year_from'],
        'yearBuiltTo' => ['as' => 'year_to'],
    ];

    public function mount(): void
    {
        if ($this->hasGeoFilter()) {
            $this->search();
        }
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['cursor', 'cursorHistory', 'response', 'loading', 'error', 'hasSearched', 'quotaExceeded', 'missingGeo'], true)) {
            return;
        }

        $this->cursor = null;
        $this->cursorHistory = [];
        $this->search();
    }

    public function search(): void
    {
        // Geo-driven guard: don't call the API without a geo filter (backend
        // would 422). Show a hint instead of an error.
        if (! $this->hasGeoFilter()) {
            $this->missingGeo = true;
            $this->response = null;
            $this->hasSearched = false;

            return;
        }

        $this->missingGeo = false;
        $this->loading = true;
        $this->error = null;
        $this->quotaExceeded = false;
        $this->hasSearched = true;

        try {
            $response = app(RegistryApi::class)->propertyExplore($this->filters(), source: 'ui_filter');

            if (isset($response['error'])) {
                $status = $response['status'] ?? 0;
                if ($status === 429) {
                    $this->quotaExceeded = true;
                } elseif ($status === 422) {
                    logger()->warning('property-explore 422', ['filters' => $this->filters()]);
                } else {
                    $this->error = 'Søgetjenesten er midlertidigt utilgængelig';
                }

                return;
            }

            $this->response = $response;
        } catch (\Throwable $e) {
            $this->error = 'Søgetjenesten er midlertidigt utilgængelig';
        } finally {
            $this->loading = false;
        }
    }

    public function nextPage(): void
    {
        $next = $this->response['data']['cursor'] ?? null;
        if (! $next) {
            return;
        }
        $this->cursorHistory[] = $this->cursor;
        $this->cursor = $next;
        $this->search();
    }

    public function previousPage(): void
    {
        if (empty($this->cursorHistory)) {
            return;
        }
        $this->cursor = array_pop($this->cursorHistory);
        $this->search();
    }

    public function sortBy(string $column): void
    {
        if (! in_array($column, ['id', 'year'], true)) {
            return;
        }
        $this->sort = ($this->sort === "{$column}_desc") ? "{$column}_asc" : "{$column}_desc";
        $this->cursor = null;
        $this->cursorHistory = [];
        $this->search();
    }

    public function downloadCsv(): void
    {
        if (! $this->hasGeoFilter()) {
            $this->missingGeo = true;

            return;
        }

        $result = app(RegistryApi::class)->createPropertyExploreCsvLink($this->filters());

        if (isset($result['error'])) {
            $this->error = 'CSV-eksport er ikke tilgængelig for din konto.';

            return;
        }

        $this->dispatch('property-explore:download', url: $result['url'] ?? '');
    }

    public function resetFilters(): void
    {
        $this->postalCodeFrom = null;
        $this->postalCodeTo = null;
        $this->municipalityCode = null;
        $this->valuationMin = null;
        $this->valuationMax = null;
        $this->yearBuiltFrom = null;
        $this->yearBuiltTo = null;
        $this->hasDebt = false;
        $this->sort = 'id_asc';
        $this->cursor = null;
        $this->cursorHistory = [];
        $this->response = null;
        $this->hasSearched = false;
        $this->error = null;
        $this->missingGeo = false;
    }

    public function render()
    {
        $view = view('metis::livewire.property-explore');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }

    protected function filters(): array
    {
        $filters = array_filter([
            'postal_code_from' => $this->validPostalOrNull($this->postalCodeFrom),
            'postal_code_to' => $this->validPostalOrNull($this->postalCodeTo),
            'municipality_code' => $this->municipalityCode ?: null,
            'valuation_min' => $this->valuationMin,
            'valuation_max' => $this->valuationMax,
            'year_built_from' => $this->yearBuiltFrom,
            'year_built_to' => $this->yearBuiltTo,
            'has_debt' => $this->hasDebt ? true : null,
            'sort' => $this->sort,
        ], fn ($v) => $v !== null && $v !== '');

        if ($this->cursor) {
            $filters['cursor'] = $this->cursor;
        }

        return $filters;
    }

    public function hasGeoFilter(): bool
    {
        return $this->validPostalOrNull($this->postalCodeFrom) !== null
            || ! empty($this->municipalityCode);
    }

    private function validPostalOrNull(?string $value): ?string
    {
        return preg_match('/^\d{4}$/', (string) $value) ? $value : null;
    }
}
