<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

class AddressTransactions extends MetisSection
{
    public array $transactions = [];

    protected function sectionTitle(): string
    {
        return __('Transaction History');
    }

    public function mount(string $query): void
    {
        $this->query = $query;
        $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
        // 🚨 Et fejlet opslag maa ikke rendere som "ingen data".
        // Se MetisSection::opslagFejlede().
        if ($this->opslagFejlede($analysis)) {
            return;
        }

        $this->transactions = $analysis['property']['transactions'] ?? [];
    }

    public function render()
    {
        return view('metis::livewire.sections.address-transactions');
    }
}
