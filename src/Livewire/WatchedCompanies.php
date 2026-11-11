<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Livewire\Concerns\HasPilotToken;
use TheFountainhead\Metis\Services\LookupAccess;
use TheFountainhead\Metis\Services\QuotaExceededException;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Overvågede selskaber: de selskaber brugeren selv følger, med antal ejendomme direkte og i
 * hele koncernen. Fanger låntagere uden tinglyst pant, som /engagementer ikke kan se
 * (målt 11/11-2026: 15 af Draupnirs 49 låntagere).
 *
 * Kun brugerens egne valg. Ingen gæld, intet pant og ingen søgning på tværs af registret
 * (tinglysningslovens § 50 c). Detaljerne ligger på selskabssiden.
 */
class WatchedCompanies extends Component
{
    use HasPilotToken;

    /** @var list<array<string, mixed>>|null null = ikke hentet (endnu) */
    public ?array $rows = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        if ($this->hasUserToken()) {
            $this->load();
        }
    }

    public function load(): void
    {
        // 🚨 Gaten skal sidde HER: `load` er offentlig over /livewire/update.
        if (! app(LookupAccess::class)->harPilotAdgang()) {
            $this->errorMessage = __('Siden kræver en bekræftet pilotbruger.');

            return;
        }

        $this->reset(['rows', 'errorMessage']);

        try {
            $response = app(RegistryApi::class)->listWatchedCompanies();
        } catch (QuotaExceededException) {
            $this->errorMessage = __('Kvoten er brugt op. Prøv igen senere.');

            return;
        }

        // Et svar uden data-liste er IKKE "du følger ingen" — det er en fejl.
        if (isset($response['error']) || ! is_array($response['data'] ?? null)) {
            $this->errorMessage = ($response['status'] ?? null) === 403
                ? __('Siden kræver en bekræftet pilotbruger.')
                : __('Kunne ikke hente de overvågede selskaber. Prøv igen.');

            return;
        }

        $this->rows = array_values($response['data']);
    }

    public function render()
    {
        $view = view('metis::livewire.watched-companies');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
