<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
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
    #[Locked]
    public string $key = '';

    public ?array $engagement = null;

    public ?array $meta = null;

    public ?string $errorMessage = null;

    public bool $unbound = false;

    public bool $notFound = false;

    public function mount(string $key): void
    {
        $this->key = $key;

        if ($this->hasUserToken()) {
            $this->load();
        }
    }

    public function hasUserToken(): bool
    {
        return ! empty(session('metis_user_token'));
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
