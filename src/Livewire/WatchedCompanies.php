<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
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
    /** @var list<array<string, mixed>>|null null = ikke hentet (endnu) */
    public ?array $rows = null;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        if ($this->erPilot()) {
            $this->load();
        }
    }

    /**
     * Én regel for både visning og hentning: brugerens EGET token og en bekræftet pilot.
     * Eget token også når gaten er slået fra (embedded): uden det ville client() falde tilbage
     * til tenantens fælles nøgle og vise tenantens watches, ikke brugerens egne valg.
     */
    public function erPilot(): bool
    {
        return filled(session('metis_user_token')) && app(LookupAccess::class)->harPilotAdgang();
    }

    public function load(): void
    {
        // 🚨 Gaten skal sidde HER: `load` er offentlig over /livewire/update.
        if (! $this->erPilot()) {
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
            $this->errorMessage = match ($response['status'] ?? null) {
                401, 403 => __('Din adgang er udløbet. Bekræft din arbejdsmail igen.'),
                404 => __('Siden er ikke klar endnu. Prøv igen senere.'),
                default => __('Kunne ikke hente de overvågede selskaber. Prøv igen.'),
            };

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
