<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use TheFountainhead\Metis\Exports\SampantskemaExport;
use TheFountainhead\Metis\Livewire\Concerns\HasPilotToken;
use TheFountainhead\Metis\Services\QuotaExceededException;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Ét engagement: prioritetsstige pr. ejendom, ændringer siden egen tinglysning,
 * og låntagerne. Regnskab og roller for hvert ejer-selskab lånes fra de
 * eksisterende selskabssektioner (CompanyInfo, CompanyRoles).
 *
 * Nøglen kommer fra API'et (ejergruppe, fx `c22222222+c33333333`) og bruges kun
 * til at slå op i brugerens EGNE engagementer; et fremmed CVR i nøglen giver
 * 404, ikke data.
 */
class Engagement extends Component
{
    use HasPilotToken;

    #[Locked]
    public string $key = '';

    public ?array $engagement = null;

    public ?array $meta = null;

    public ?string $errorMessage = null;

    public bool $unbound = false;

    public bool $notFound = false;

    /** Fejl ved dannelse af regnearket. Nulstilles ved hvert forsøg, så en gammel fejl ikke bliver hængende. */
    public ?string $exportFejl = null;

    public function mount(string $key): void
    {
        $this->key = $key;

        if ($this->hasUserToken()) {
            $this->load();
        }
    }

    public function load(): void
    {
        if (! $this->hasUserToken()) {
            return;
        }

        $this->reset(['engagement', 'meta', 'errorMessage', 'unbound', 'notFound']);

        try {
            $response = app(RegistryApi::class)->fetchEngagement($this->key);
        } catch (QuotaExceededException) {
            $this->errorMessage = __('Kvoten er brugt op. Prøv igen senere.');

            return;
        }

        if ($response === null || isset($response['error'])) {
            $status = $response['status'] ?? null;

            if ($status === 403) {
                $this->unbound = true;
            } elseif ($status === 404) {
                $this->notFound = true;
            } else {
                $this->errorMessage = __('Kunne ikke hente engagementet. Prøv igen.');
            }

            return;
        }

        if (! is_array($response['data'] ?? null) || empty($response['data']['key']) || empty($response['meta']['measured_at'])) {
            $this->errorMessage = __('Kunne ikke hente engagementet. Prøv igen.');

            return;
        }

        $this->engagement = $response['data'];
        $this->meta = $response['meta'];
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getCompanyOwnersProperty(): array
    {
        return array_values(array_filter($this->engagement['owners'] ?? [], fn ($o) => ($o['type'] ?? null) === 'company'));
    }

    /**
     * Pantebreve i engagementet der er lyst på mere end én ejendom, med alle ejendommene (også dem uden for
     * engagementet). Samme funktion som regnearket bruger, så siden og filen aldrig er uenige.
     *
     * @return list<array<string, mixed>>
     */
    public function getSampantProperty(): array
    {
        return SampantskemaExport::sampant($this->engagement ?? []);
    }

    /**
     * Download sampantskema og samlet pantoversigt for engagementet som regneark.
     *
     * 🚨 Gaten sidder HER: metoden er offentlig over /livewire/update. Engagementet hentes FRISK med brugerens eget
     * token (aldrig fra komponentens tilstand, som klienten kunne have sendt), så filen kun kan rumme brugerens
     * egne engagementer.
     */
    public function exportSampantskema()
    {
        $this->exportFejl = null;

        if (! $this->hasUserToken()) {
            return null;
        }

        try {
            $response = app(RegistryApi::class)->fetchEngagement($this->key);
        } catch (QuotaExceededException) {
            // Egen fejltekst: $errorMessage ville erstatte hele det indlæste engagement med en fejlboks.
            $this->exportFejl = __('Kvoten er brugt op. Prøv igen senere.');

            return null;
        }
        if (! is_array($response['data'] ?? null) || isset($response['error']) || empty($response['data']['key'])) {
            $this->exportFejl = __('Kunne ikke hente engagementet til regnearket. Prøv igen.');

            return null;
        }

        try {
            $export = new SampantskemaExport($response['data'], is_array($response['meta'] ?? null) ? $response['meta'] : []);
        } catch (\RuntimeException $e) {
            // phpoffice/phpspreadsheet er en valgfri afhængighed: vis en besked i stedet for at vælte siden.
            $this->exportFejl = __('Regneark kan ikke dannes på denne installation.');

            return null;
        }

        return response()->streamDownload(function () use ($export) {
            $stream = fopen('php://output', 'wb');
            $export->writeTo($stream);
            fclose($stream);
        }, 'sampantskema-'.now()->format('Y-m-d').'.xlsx', ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet']);
    }

    public function getPersonOwnerCountProperty(): int
    {
        return count(array_filter($this->engagement['owners'] ?? [], fn ($o) => ($o['type'] ?? null) === 'person'));
    }

    public function render()
    {
        $view = view('metis::livewire.engagement');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
