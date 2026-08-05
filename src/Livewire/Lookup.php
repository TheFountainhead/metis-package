<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Models\MetisLookup;

class Lookup extends Component
{
    public string $type;

    public string $query;

    /**
     * Et 10-cifret tal paa /lookup/cvr/ er et CPR, ikke et CVR.
     *
     * 🚨 MAALT 5/8 (Flare #9104992, n=2 -> 14 paa seks doegn, prod-trafik):
     * brugere rammer /lookup/cvr/ med et CPR. Konsekvensen var toledet:
     *
     *   1. lookup.blade.php:24 loader OTTE selskabssektioner for type=cvr.
     *      Alle otte kalder API'et med den ugyldige vaerdi, alle faar 422, og
     *      ingen validerer foerst. Én fejlindtastning blev til en byge af
     *      uhaandterede exceptions — derfor 14 og ikke 1.
     *   2. Brugeren saa en fejlside, selvom vi HAR en CPR-side. Vi sendte dem
     *      bare ikke derhen.
     *
     * 🪤 Og et CPR blev gemt i `metis_lookups.search_term` (maalt: 4 raekker
     * under search_type='cvr'). Flare censurerer i UI'et, men vores egen
     * historik-tabel gjorde ikke. Derfor redirectes FOER MetisLookup::create().
     */
    private const CPR_PATTERN = '/^\d{10}$/';

    public function mount(string $type, string $query): void
    {
        // Redirect FOER historikken skrives: ellers lander CPR'et i
        // search_term under den forkerte type.
        if ($type === 'cvr' && preg_match(self::CPR_PATTERN, $query) === 1) {
            $this->redirect(route('metis.lookup', ['type' => 'cpr', 'query' => $query]), navigate: true);

            return;
        }

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

        rescue(fn () => MetisLookup::create($data));
    }

    public function render()
    {
        $view = view('metis::livewire.lookup');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
