<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Services\LegalName;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Långiverens cockpit: mine engagementer.
 *
 * Viser KUN det, brugerens eget selskab står med i Tinglysningen. Hvilket
 * selskab afgøres på serversiden (registry-api `users.lender_cvr`); siden
 * tager intet CVR som input, og der er ingen søgning på tværs af registret.
 * Tinglysningslovens § 50 c: tingbogsdata til kreditvurdering og belåning af
 * egne engagementer, ikke til at finde nye kunder.
 *
 * ⚠️ Forbeholdet vises ALTID sammen med tallene. En panthaver kan optræde på
 * vegne af andre kreditorer, så summen er hvad långiveren står ANFØRT for.
 */
class Engagements extends Component
{
    public const CAVEAT_FALLBACK = 'Beløbet er hvad långiveren står anført for i Tinglysningen. '
        .'En panthaver kan optræde på vegne af andre kreditorer, så tallet er ikke nødvendigvis egen finansiering.';

    /** @var list<array<string, mixed>>|null null = ikke hentet (endnu) */
    public ?array $rows = null;

    public ?array $meta = null;

    public ?string $errorMessage = null;

    /** Brugeren er logget ind, men ikke knyttet til noget långiverselskab (API 403). */
    public bool $unbound = false;

    public string $sort = 'lender_kr';

    public function mount(): void
    {
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
        $this->reset(['rows', 'meta', 'errorMessage', 'unbound']);

        $response = app(RegistryApi::class)->fetchEngagements();

        if ($response === null || isset($response['error'])) {
            if (($response['status'] ?? null) === 403) {
                $this->unbound = true;

                return;
            }

            $this->errorMessage = __('Kunne ikke hente engagementer. Prøv igen.');

            return;
        }

        $this->rows = $response['data'] ?? [];
        $this->meta = $response['meta'] ?? [];
    }

    public function sortBy(string $field): void
    {
        if (in_array($field, ['lender_kr', 'worst_ahead_kr', 'latest_change_at', 'total_debt_kr'], true)) {
            $this->sort = $field;
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function getSortedProperty(): array
    {
        $rows = $this->rows ?? [];
        $field = $this->sort;

        usort($rows, fn ($a, $b) => ($b[$field] ?? '') <=> ($a[$field] ?? ''));

        return $rows;
    }

    public function getLenderLabelProperty(): string
    {
        $name = $this->meta['lender']['name'] ?? null;

        return $name ? LegalName::format($name) : ($this->meta['lender']['cvr'] ?? '');
    }

    public static function ownerLabel(array $engagement): string
    {
        $labels = array_map(fn ($o) => $o['type'] === 'company' ? LegalName::format((string) $o['name']) : $o['label'], $engagement['owners'] ?? []);

        if ($labels === []) {
            return __('Ejer ukendt');
        }

        return implode(' + ', $labels);
    }

    public function render()
    {
        $view = view('metis::livewire.engagements');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
