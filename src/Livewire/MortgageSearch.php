<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Services\RegistryApi;

class MortgageSearch extends Component
{
    public string $creditor = '';
    public ?int $minAmount = null;
    public ?int $maxAmount = null;
    public ?float $minRate = null;
    public ?float $maxRate = null;
    public string $postalCodeFrom = '';
    public string $postalCodeTo = '';

    public ?array $results = null;
    public ?array $stats = null;
    public ?array $creditors = null;
    public bool $loading = false;

    public function mount(): void
    {
        $api = app(RegistryApi::class);
        $this->stats = rescue(fn () => $api->getMortgageStats());
        $this->creditors = rescue(fn () => $api->getMortgageCreditors());
    }

    public function search(): void
    {
        $this->loading = true;

        $filters = array_filter([
            'creditor' => $this->creditor ?: null,
            'min_amount' => $this->minAmount,
            'max_amount' => $this->maxAmount,
            'min_rate' => $this->minRate,
            'max_rate' => $this->maxRate,
            'postal_code_from' => $this->postalCodeFrom ?: null,
            'postal_code_to' => $this->postalCodeTo ?: null,
            'limit' => 25,
        ]);

        $api = app(RegistryApi::class);
        $this->results = rescue(fn () => $api->searchMortgages($filters));
        $this->loading = false;
    }

    public function selectCreditor(string $creditor): void
    {
        $this->creditor = $creditor;
        $this->search();
    }

    public function render()
    {
        return view('metis::livewire.mortgage-search');
    }
}
