<?php

namespace TheFountainhead\Metis\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use TheFountainhead\Metis\Models\MetisLookup;

class Logs extends Component
{
    use WithPagination;

    public string $email = '';

    public string $searchType = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public function updatingEmail(): void
    {
        $this->resetPage();
    }

    public function updatingSearchType(): void
    {
        $this->resetPage();
    }

    public function updatingDateFrom(): void
    {
        $this->resetPage();
    }

    public function updatingDateTo(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $query = MetisLookup::query();

        if ($this->email) {
            $query->where('email', 'like', "%{$this->email}%");
        }

        if ($this->searchType) {
            $query->where('search_type', $this->searchType);
        }

        if ($this->dateFrom) {
            $query->whereDate('created_at', '>=', $this->dateFrom);
        }

        if ($this->dateTo) {
            $query->whereDate('created_at', '<=', $this->dateTo);
        }

        $logs = $query->latest()->paginate(50);

        return view('metis::livewire.admin.logs', [
            'logs' => $logs,
        ])->layout('metis::layouts.admin');
    }
}
