<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Models\MetisLookup;

class Lookup extends Component
{
    public string $type;

    public string $query;

    public function mount(string $type, string $query): void
    {
        $this->type = $type;
        $this->query = $query;

        // Save to history — in embedded mode use auth user, in standalone mode use session
        $data = [
            'search_type' => $type,
            'search_term' => $query,
            'ip_address' => request()->ip(),
            'is_cross_reference' => false,
        ];

        if (config('metis.mode') === 'embedded' && auth()->check()) {
            $data['email'] = auth()->user()->email;
        } else {
            $data['session_id'] = session()->getId();
            $data['email'] = session('metis_verified_email');
        }

        MetisLookup::create($data);
    }

    public function render()
    {
        return view('metis::livewire.lookup');
    }
}
