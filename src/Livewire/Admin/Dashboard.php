<?php

namespace TheFountainhead\Metis\Livewire\Admin;

use Livewire\Component;
use TheFountainhead\Metis\Models\MetisLead;
use TheFountainhead\Metis\Models\MetisLookup;

class Dashboard extends Component
{
    public function render()
    {
        $today = now()->startOfDay();

        return view('metis::livewire.admin.dashboard', [
            'searchesToday' => MetisLookup::where('created_at', '>=', $today)->count(),
            'leadsToday' => MetisLead::where('created_at', '>=', $today)->count(),
            'companiesToday' => MetisLead::where('created_at', '>=', $today)->distinct('cvr')->count('cvr'),
            'latestLeads' => MetisLead::latest()->limit(10)->get(),
            'popularSearches' => MetisLookup::where('created_at', '>=', now()->subDays(7))
                ->select('search_term', 'search_type')
                ->selectRaw('count(*) as count')
                ->groupBy('search_term', 'search_type')
                ->orderByDesc('count')
                ->limit(10)
                ->get(),
        ]);
    }
}
