<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * F5 person-watching: 2-step modal Følg-flow.
 *
 * Step 1: User clicks "Følg" → fetches CVR-deltager-kandidater for the
 *         searched name via /v1/cvr/person-disambiguate.
 * Step 2: User clicks specific candidate → POST /v1/watchlists med
 *         watch_value = enhedsnummer (stabil CVR person-id), display_label
 *         = "Navn, by, top-selskab".
 *
 * Solves name-collision: bruger overvåger en specifik person, ikke et navn.
 * Snapshot/diff/alert er præcis pr. enhedsnummer.
 */
class PersonFollowButton extends Component
{
    public string $name = '';
    public bool $open = false;
    public bool $loading = false;
    public ?string $error = null;

    /** @var array<int, array<string,mixed>> */
    public array $candidates = [];

    /**
     * Watch-state per enhedsnummer i candidates-listen:
     * ['4001000001' => ['is_followed' => true, 'watchlist_id' => 42], ...]
     *
     * @var array<string, array{is_followed: bool, watchlist_id: ?int}>
     */
    public array $followState = [];

    public function mount(string $name): void
    {
        $this->name = $name;
    }

    public function openModal(): void
    {
        $this->open = true;
        $this->error = null;

        if (empty($this->candidates)) {
            $this->fetchCandidates();

            return;
        }

        // Candidates are cached across open/close, but follow-state may have
        // changed elsewhere (other tab, AlertsInbox) — re-sync it on reopen.
        $this->refreshFollowState();
    }

    public function closeModal(): void
    {
        $this->open = false;
    }

    public function fetchCandidates(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $api = app(RegistryApi::class);
            $result = $api->disambiguatePerson($this->name, 20);

            if (isset($result['error'])) {
                $this->error = __('Kunne ikke hente person-kandidater.');
                $this->loading = false;

                return;
            }

            $this->candidates = $result['candidates'] ?? [];

            $this->refreshFollowState();
        } catch (\Throwable $e) {
            $this->error = __('Uventet fejl ved opslag.');
        } finally {
            $this->loading = false;
        }
    }

    public function follow(string $enhedsnummer): void
    {
        // Guard against rapid double-clicks: two requests can race past the
        // disabled-button window and would create duplicate watchlists.
        if ($this->followState[$enhedsnummer]['is_followed'] ?? false) {
            return;
        }

        $candidate = collect($this->candidates)->firstWhere('enhedsnummer', $enhedsnummer);
        if (! $candidate) {
            $this->error = __('Ukendt kandidat.');

            return;
        }

        $this->loading = true;
        $this->error = null;

        try {
            $api = app(RegistryApi::class);
            $label = $this->candidateLabel($candidate);

            $resp = $api->createWatchlist(
                'person',
                $enhedsnummer,
                $label,
                ['person_new_company', 'person_role_change', 'person_role_ended'],
            );

            if (isset($resp['error'])) {
                $this->error = __('Kunne ikke følge personen. Prøv igen.');

                return;
            }

            $this->followState[$enhedsnummer] = [
                'is_followed' => true,
                'watchlist_id' => data_get($resp, 'data.id'),
            ];
        } catch (\Throwable $e) {
            $this->error = __('Uventet fejl ved opret.');
        } finally {
            $this->loading = false;
        }
    }

    public function unfollow(string $enhedsnummer): void
    {
        $state = $this->followState[$enhedsnummer] ?? null;
        if (! $state || ! ($state['watchlist_id'] ?? null)) {
            return;
        }

        $this->loading = true;
        $this->error = null;

        try {
            $api = app(RegistryApi::class);
            $resp = $api->deleteWatchlist($state['watchlist_id']);

            if (isset($resp['error'])) {
                $this->error = __('Kunne ikke fjerne følge-status.');

                return;
            }

            $this->followState[$enhedsnummer] = [
                'is_followed' => false,
                'watchlist_id' => null,
            ];
        } catch (\Throwable $e) {
            $this->error = __('Uventet fejl.');
        } finally {
            $this->loading = false;
        }
    }

    protected function refreshFollowState(): void
    {
        $enhedsnumre = collect($this->candidates)->pluck('enhedsnummer')->filter()->all();
        if (empty($enhedsnumre)) {
            return;
        }

        $api = app(RegistryApi::class);
        $items = array_map(fn ($e) => ['type' => 'person', 'value' => $e], $enhedsnumre);
        $check = rescue(fn () => $api->checkBatch($items), null, false);

        $rows = data_get($check, 'data', []);
        $this->followState = collect($rows)->mapWithKeys(fn ($r) => [
            ($r['value'] ?? '') => [
                'is_followed' => (bool) ($r['is_followed'] ?? false),
                'watchlist_id' => $r['watchlist_id'] ?? null,
            ],
        ])->all();
    }

    public function candidateLabel(array $candidate): string
    {
        $name = $candidate['name'] ?? '';
        $city = $candidate['address']['city'] ?? null;
        $companies = $candidate['companies'] ?? [];
        $topCompany = $companies[0]['name'] ?? null;

        $parts = array_filter([$name, $city, $topCompany]);

        return implode(', ', $parts);
    }

    public function render()
    {
        return view('metis::livewire.person-follow-button');
    }
}
