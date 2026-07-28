<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Lazy;
use Livewire\Component;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Titel-linjen øverst på lookup-siden: navnet på det man har søgt frem
 * (Frederiks ønske 28/7 — "øverst i søgeresultat bør man kunne se navn").
 *
 * Pr. opslags-type:
 *  - person (navnesøgning) og address: søgetermen ER navnet/adressen — vises
 *    direkte uden opslag.
 *  - cvr: CVR-nummeret vises som det samme-sekund-svar; selskabsnavnet
 *    resolves LAZY via fetchCompanyInfo (24h-cachet, så det er et cache-hit
 *    på alle gensyn og ét let kald første gang). Fejler opslaget, bliver
 *    CVR-nummeret stående — titlen må aldrig blokere eller fejle siden.
 *  - cpr: neutral "Personopslag". search-by-cpr-payloaden bærer ikke
 *    personens navn (verificeret i 2b), og det RÅ CPR hører ikke hjemme som
 *    sidetitel selv om Personinformation-kortet viser det som indhold.
 */
#[Lazy]
class LookupTitle extends Component
{
    public string $type;

    public string $query;

    public ?string $title = null;

    public function mount(string $type, string $query): void
    {
        $this->type = $type;
        $this->query = $query;

        $this->title = match ($type) {
            'person', 'address' => $query,
            'cvr' => $this->companyTitle($query),
            default => __('Personopslag'),
        };
    }

    protected function companyTitle(string $cvr): string
    {
        $info = rescue(fn () => app(RegistryApi::class)->fetchCompanyInfo($cvr), report: false);

        $name = is_array($info) && ! isset($info['error']) ? ($info['name'] ?? null) : null;

        return $name ? "{$name}" : "CVR {$cvr}";
    }

    public function placeholder()
    {
        // Skeleton mens lazy-loadet kører: vis søgetermen med det samme for
        // person/adresse (kendt synkront), ellers en diskret bar.
        $immediate = in_array($this->type, ['person', 'address'], true) ? $this->query : null;

        return view('metis::livewire.lookup-title-placeholder', ['immediate' => $immediate]);
    }

    public function render()
    {
        return view('metis::livewire.lookup-title');
    }
}
