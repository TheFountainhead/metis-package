<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Models\MetisLookup;
use TheFountainhead\Metis\Services\RegistryApi;
use TheFountainhead\Metis\Services\SearchDetector;

class Index extends Component
{
    public string $search = '';

    public string $detectedType = '';

    public ?string $overrideType = null;

    public array $addressSuggestions = [];

    public array $companySuggestions = [];

    public bool $companySearchDone = false;

    public function updatedSearch(string $value): void
    {
        $this->overrideType = null;
        $this->addressSuggestions = [];
        $this->companySuggestions = [];
        $this->companySearchDone = false;
        $this->detectedType = strlen($value) >= 2 ? (new SearchDetector)->detect($value) : '';

        if (strlen($value) < 3) {
            return;
        }

        if ($this->detectedType === 'address') {
            $api = app(RegistryApi::class);
            $this->addressSuggestions = $api->addressAutocomplete($value, 8);
        }
    }

    public function searchCompanies(): void
    {
        $query = trim($this->search);
        if (strlen($query) < 2) {
            return;
        }

        $api = app(RegistryApi::class);
        $results = rescue(fn () => $api->searchByName($query), []);
        $this->companySuggestions = collect($results)
            ->take(10)
            ->map(fn ($c) => [
                'name' => $c['name'] ?? $c['cvr'],
                'cvr' => $c['cvr'] ?? '',
                'company_type' => $c['company_type'] ?? '',
                'status' => $c['status'] ?? '',
            ])
            ->toArray();
        $this->companySearchDone = true;
    }

    public function selectAddress(int $index): void
    {
        $selected = $this->addressSuggestions[$index] ?? null;
        if ($selected) {
            $this->search = $selected['tekst'] ?? '';
            $this->addressSuggestions = [];
            $this->detectedType = 'address';
        }
    }

    public function selectCompany(string $cvr): void
    {
        if ($cvr) {
            $this->redirect(route('metis.lookup', ['type' => 'cvr', 'query' => $cvr]));
        }
    }

    public function setType(string $type): void
    {
        $this->overrideType = $type;
        $this->addressSuggestions = [];
        $this->companySuggestions = [];
        $this->companySearchDone = false;

        if ($type === 'company' && strlen($this->search) >= 2) {
            $this->searchCompanies();
        } elseif ($type === 'address' && strlen($this->search) >= 2) {
            $api = app(RegistryApi::class);
            $this->addressSuggestions = $api->addressAutocomplete($this->search, 8);
        }
    }

    public function lookup(): void
    {
        $query = trim($this->search);
        if (strlen($query) < 2) {
            return;
        }

        $type = $this->overrideType ?? $this->detectedType;
        if (! $type) {
            $type = (new SearchDetector)->detect($query);
        }

        // Company name search -> search and show results
        if ($type === 'company_name' || $type === 'company') {
            $this->searchCompanies();

            return;
        }

        $this->redirect(route('metis.lookup', ['type' => $type, 'query' => $query]));
    }

    public function render()
    {
        // In embedded mode, query by auth user. In standalone mode, query by session.
        if (config('metis.mode') === 'embedded' && auth()->check()) {
            $lookups = MetisLookup::where('email', auth()->user()->email)
                ->latest()
                ->limit(20)
                ->get();
        } else {
            $lookups = MetisLookup::where('session_id', session()->getId())
                ->latest()
                ->limit(20)
                ->get();
        }

        return view('metis::livewire.index', [
            'lookups' => $lookups,
        ]);
    }
}
