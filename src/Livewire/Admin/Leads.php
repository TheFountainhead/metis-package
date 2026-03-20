<?php

namespace TheFountainhead\Metis\Livewire\Admin;

use Livewire\Component;
use Livewire\WithPagination;
use TheFountainhead\Metis\Models\MetisLead;

class Leads extends Component
{
    use WithPagination;

    public string $search = '';

    public string $sortField = 'created_at';

    public string $sortDirection = 'desc';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function sortBy(string $field): void
    {
        if ($this->sortField === $field) {
            $this->sortDirection = $this->sortDirection === 'asc' ? 'desc' : 'asc';
        } else {
            $this->sortField = $field;
            $this->sortDirection = 'desc';
        }
    }

    public function render()
    {
        $query = MetisLead::query();

        if ($this->search) {
            $query->where(function ($q) {
                $q->where('email', 'like', "%{$this->search}%")
                    ->orWhere('company_name', 'like', "%{$this->search}%")
                    ->orWhere('cvr', 'like', "%{$this->search}%")
                    ->orWhere('domain', 'like', "%{$this->search}%");
            });
        }

        $leads = $query->orderBy($this->sortField, $this->sortDirection)
            ->paginate(25);

        return view('metis::livewire.admin.leads', [
            'leads' => $leads,
        ])->layout('metis::layouts.admin');
    }
}
