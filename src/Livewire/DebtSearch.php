<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Phase 1 search-by-criteria UI for the Metis debt domain.
 *
 * Lazy-load: no API call until the user clicks "Søg" or changes a filter.
 * Filters are URL-bound via #[Url] attributes so searches are shareable.
 *
 * State machine:
 *   - initial    → filters mounted with defaults, no API call yet
 *   - loading    → wire:loading skeleton
 *   - empty      → "Ingen lån matcher dine filtre"
 *   - results    → aggregate panel + paginated detail-rows
 *   - error      → "Søgetjenesten er midlertidigt utilgængelig"
 *   - quota      → "Du har nået dagens søgekvote"
 */
class DebtSearch extends Component
{
    public ?float $minRate = 8.0;
    public ?float $maxRate = 25.0;
    public string $ownerType = 'company';
    public ?string $debtType = null;
    public ?string $postalCodeFrom = null;
    public ?string $postalCodeTo = null;
    public ?string $creditorContains = null;
    public ?int $minAmount = null;
    public ?int $maxAmount = null;
    public ?string $registeredFrom = null;
    public ?string $registeredTo = null;

    public ?array $response = null;
    public ?string $cursor = null;

    /**
     * Stack of cursors visited via nextPage(), used for previousPage().
     * Cursor pagination is forward-only on the server (HMAC-signed); we
     * implement back-navigation client-side by remembering the path taken.
     * Lost on full page reload — acceptable trade for Phase 1.
     */
    public array $cursorHistory = [];

    public bool $hasSearched = false;
    public bool $loading = false;
    public ?string $error = null;
    public bool $quotaExceeded = false;

    public string $csvUrl = '';

    protected $queryString = [
        'minRate' => ['as' => 'rate_min', 'except' => 8.0],
        'maxRate' => ['as' => 'rate_max', 'except' => 25.0],
        'ownerType' => ['as' => 'owner', 'except' => 'company'],
        'debtType' => ['as' => 'type'],
        'postalCodeFrom' => ['as' => 'postal_from'],
        'postalCodeTo' => ['as' => 'postal_to'],
        'creditorContains' => ['as' => 'creditor'],
        'minAmount' => ['as' => 'amount_min'],
        'maxAmount' => ['as' => 'amount_max'],
        'registeredFrom' => ['as' => 'reg_from'],
        'registeredTo' => ['as' => 'reg_to'],
    ];

    public function mount(): void
    {
        if ($this->hasNonDefaultFilters()) {
            $this->search();
        }
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['cursor', 'cursorHistory', 'response', 'csvUrl', 'loading', 'error', 'hasSearched', 'quotaExceeded'], true)) {
            return;
        }

        $this->cursor = null;
        $this->cursorHistory = [];
        $this->search();
    }

    public function search(): void
    {
        $this->loading = true;
        $this->error = null;
        $this->quotaExceeded = false;
        $this->hasSearched = true;

        try {
            $api = app(RegistryApi::class);
            $response = $api->debtSearch($this->filters(), source: 'ui_filter');

            if (isset($response['error'])) {
                $status = $response['status'] ?? 0;
                if ($status === 429) {
                    $this->quotaExceeded = true;
                } elseif ($status === 422) {
                    // Validation error means a filter slipped past client-side
                    // checks. Don't shout at the user — the malformed filter
                    // would have been stripped if it were known. Log instead.
                    $this->error = null;
                    logger()->warning('debt-search 422 validation error', [
                        'filters' => $this->filters(),
                        'response' => $response,
                    ]);
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
        $next = $this->response['pagination']['next_cursor'] ?? null;
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

    public function resetFilters(): void
    {
        $this->minRate = 8.0;
        $this->maxRate = 25.0;
        $this->ownerType = 'company';
        $this->debtType = null;
        $this->postalCodeFrom = null;
        $this->postalCodeTo = null;
        $this->creditorContains = null;
        $this->minAmount = null;
        $this->maxAmount = null;
        $this->registeredFrom = null;
        $this->registeredTo = null;
        $this->cursor = null;
        $this->cursorHistory = [];
        $this->response = null;
        $this->hasSearched = false;
        $this->error = null;
    }

    public function downloadCsv(): void
    {
        $api = app(RegistryApi::class);
        $result = $api->createDebtSearchCsvLink($this->filters());

        if (isset($result['error'])) {
            $this->error = 'CSV-eksport er ikke tilgængelig for din konto.';
            return;
        }

        $this->csvUrl = $result['url'] ?? '';
        $this->dispatch('debt-search:download', url: $this->csvUrl);
    }

    public function render()
    {
        $view = view('metis::livewire.debt-search');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }

    protected function filters(): array
    {
        $filters = array_filter([
            'min_rate' => $this->minRate,
            'max_rate' => $this->maxRate,
            'owner_type' => $this->ownerType,
            'debt_type' => $this->debtType,
            'postal_code_from' => $this->validPostalOrNull($this->postalCodeFrom),
            'postal_code_to' => $this->validPostalOrNull($this->postalCodeTo),
            'creditor_contains' => $this->validCreditorOrNull($this->creditorContains),
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'registered_from' => $this->validDateOrNull($this->registeredFrom),
            'registered_to' => $this->validDateOrNull($this->registeredTo),
        ], fn ($v) => $v !== null && $v !== '');

        if ($this->cursor) {
            $filters['cursor'] = $this->cursor;
        }

        return $filters;
    }

    /**
     * Skip partially-typed postal codes from the API call. Backend validates
     * with regex /^\d{4}$/ and returns 422 on '1' or '12'; that surfaces as
     * "tjeneste utilgængelig" in the UI which is misleading. Stripping
     * malformed values means the user sees broader results while still typing,
     * and the postal filter applies as soon as they enter the 4th digit.
     */
    private function validPostalOrNull(?string $value): ?string
    {
        return preg_match('/^\d{4}$/', (string) $value) ? $value : null;
    }

    /**
     * Backend requires creditor_contains to be at least 3 chars. Same
     * principle as postal codes — strip while user is typing.
     */
    private function validCreditorOrNull(?string $value): ?string
    {
        return strlen((string) $value) >= 3 ? $value : null;
    }

    /**
     * Backend requires Y-m-d format. Strip malformed input client-side so
     * an in-progress date entry doesn't trigger a 422 round-trip.
     */
    private function validDateOrNull(?string $value): ?string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $value) ? $value : null;
    }

    private function hasNonDefaultFilters(): bool
    {
        return $this->minRate !== 8.0
            || $this->maxRate !== 25.0
            || $this->ownerType !== 'company'
            || $this->debtType !== null
            || $this->postalCodeFrom !== null
            || $this->postalCodeTo !== null
            || $this->creditorContains !== null
            || $this->minAmount !== null
            || $this->maxAmount !== null
            || $this->registeredFrom !== null
            || $this->registeredTo !== null;
    }
}
