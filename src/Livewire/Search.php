<?php

namespace TheFountainhead\Metis\Livewire;

use Illuminate\Support\Facades\Http;
use Livewire\Attributes\On;
use Livewire\Component;
use TheFountainhead\Metis\Models\MetisLookup;
use TheFountainhead\Metis\Services\RegistryApi;
use TheFountainhead\Metis\Services\SearchDetector;

class Search extends Component
{
    public string $query = '';

    public ?array $result = null;

    public ?string $resultType = null;

    public bool $loading = false;

    public bool $error = false;

    public ?string $errorMessage = null;

    public bool $cprBlocked = false;

    public bool $rateLimited = false;

    public ?int $rateLimitResetsAt = null;

    public int $retryCount = 0;

    public array $chips = [];

    public int $visibleCompanies = 25;

    public int $visiblePersons = 25;

    public string $turnstileToken = '';

    public function mount(): void
    {
        $allChips = [
            ['label' => 'Hvem ejer Bredgade 40?', 'query' => 'Bredgade 40, 1260 København'],
            ['label' => 'Vis selskaber for CVR 12345678', 'query' => '12345678'],
            ['label' => 'Carlsberg A/S', 'query' => 'Carlsberg A/S'],
            ['label' => 'Ejendomme på Nørrebrogade', 'query' => 'Nørrebrogade 15, 2200'],
            ['label' => 'Hvem er bag CVR 87654321?', 'query' => '87654321'],
            ['label' => 'Vestergade 1, Roskilde', 'query' => 'Vestergade 1, 4000 Roskilde'],
        ];

        $this->chips = collect($allChips)->random(3)->values()->all();

        // Handle ?q= on page load — guard against CPR in URL
        if ($q = request()->query('q')) {
            if (preg_match('/^\d{6}-?\d{4}$/', trim($q))) {
                $this->redirect('/');

                return;
            }
            $this->query = $q;
            $this->search();
        }
    }

    public function search(): void
    {
        $this->reset(['result', 'resultType', 'error', 'errorMessage', 'cprBlocked', 'rateLimited']);
        $this->retryCount = 0;

        $query = trim($this->query);
        if (empty($query)) {
            return;
        }

        if (! $this->verifyTurnstile()) {
            $this->error = true;
            $this->errorMessage = 'captcha_failed';

            return;
        }

        $detector = new SearchDetector;
        $type = $detector->detect($query);

        if ($type === 'cpr') {
            $this->cprBlocked = true;

            return;
        }

        // Check email gate (lookup 2+) before rate limit — skip when gating disabled (embedded mode)
        if (config('metis.gating.enabled', true)) {
            $lookupCount = session('metis_lookup_count', 0);
            $hasVerifiedEmail = session('metis_verified_email');

            $freeLookups = config('metis.gating.free_lookups', 1);
            if ($lookupCount >= $freeLookups && ! $hasVerifiedEmail) {
                $this->dispatch('show-email-gate');

                return;
            }

            // Check rate limit (applies after email verification)
            if ($this->isRateLimited()) {
                $this->rateLimited = true;

                return;
            }
        }

        $this->loading = true;
        $this->performSearch($type, $query);
        $this->loading = false;

        // Log only successful searches
        if ($this->result) {
            $this->logLookup($type, $query, isCrossReference: false);
            $this->dispatchSearchCompleted();
        }
    }

    public function retry(): void
    {
        $this->retryCount++;

        if ($this->retryCount >= 3) {
            $this->error = true;
            $this->errorMessage = 'permanent';

            return;
        }

        $this->search();
    }

    public function fillChip(string $chip): void
    {
        $this->query = $chip;
        $this->search();
    }

    public function crossReference(string $type, string $value): void
    {
        if ($type === 'cpr') {
            $this->cprBlocked = true;

            return;
        }

        $this->query = $value;
        $this->reset(['result', 'resultType', 'error', 'errorMessage', 'cprBlocked', 'rateLimited']);

        $this->loading = true;
        $this->performSearch($type, $value);
        $this->loading = false;

        if ($this->result) {
            $this->logLookup($type, $value, isCrossReference: true);
            $this->dispatchSearchCompleted();
        }

        if ($type !== 'cpr') {
            $this->dispatch('update-url', query: $value);
        }
    }

    public function loadMore(string $type): void
    {
        match ($type) {
            'companies' => $this->visibleCompanies += 25,
            'persons' => $this->visiblePersons += 25,
            default => null,
        };
    }

    public function retrySection(string $section): void
    {
        $api = app(RegistryApi::class);

        if ($section === 'valuation' && isset($this->result['property']['matrikel'])) {
            $valuation = $api->fetchValuation($this->result['property']['matrikel']);
            if ($valuation && ! isset($valuation['error'])) {
                $this->result['valuation'] = $valuation;
                unset($this->result['valuation_error']);
            }
        }
    }

    #[On('navigate-to')]
    public function navigateTo(string $query): void
    {
        $this->query = $query;
        $this->search();
    }

    #[On('email-verified')]
    public function onEmailVerified(string $email): void
    {
        session(['metis_verified_email' => $email]);
        $this->search();
    }

    protected function verifyTurnstile(): bool
    {
        if (app()->environment('local', 'testing')) {
            return true;
        }

        if (empty(config('metis.turnstile.secret_key'))) {
            return true;
        }

        $response = Http::asForm()->post('https://challenges.cloudflare.com/turnstile/v0/siteverify', [
            'secret' => config('metis.turnstile.secret_key'),
            'response' => $this->turnstileToken,
            'remoteip' => request()->ip(),
        ]);

        return $response->json('success', false);
    }

    protected function isRateLimited(): bool
    {
        $email = session('metis_verified_email');
        $windowStart = session('metis_lookup_window_start', 0);
        $count = session('metis_lookup_count', 0);

        if (now()->timestamp - $windowStart > 3600) {
            session(['metis_lookup_count' => 0, 'metis_lookup_window_start' => now()->timestamp]);

            return false;
        }

        $limit = $email
            ? config('metis.rate_limits.verified', 10)
            : config('metis.rate_limits.anonymous', 1);

        if ($count >= $limit) {
            $this->rateLimitResetsAt = $windowStart + 3600;

            return true;
        }

        return false;
    }

    protected function logLookup(string $type, string $query, bool $isCrossReference): void
    {
        MetisLookup::create([
            'session_id' => session()->getId(),
            'email' => session('metis_verified_email'),
            'search_type' => $type,
            'search_term' => $query,
            'ip_address' => request()->ip(),
            'is_cross_reference' => $isCrossReference,
        ]);

        if (! $isCrossReference) {
            session(['metis_lookup_count' => session('metis_lookup_count', 0) + 1]);
            if (! session('metis_lookup_window_start')) {
                session(['metis_lookup_window_start' => now()->timestamp]);
            }
        }
    }

    protected function performSearch(string $type, string $query): void
    {
        $api = app(RegistryApi::class);

        $result = match ($type) {
            'cvr' => $api->fetchCompany($query),
            'company_name' => ['companies' => $api->searchByName($query)],
            'name' => ['persons' => $api->searchPersonByName($query), 'companies' => $api->searchByName($query)],
            'address' => $api->fetchPropertyByAddress($query),
            default => null,
        };

        if ($result === null || isset($result['error'])) {
            $this->error = true;
            $this->errorMessage = 'api_error';

            return;
        }

        if (empty($result) || (is_array($result) && empty(array_filter($result)))) {
            $this->error = true;
            $this->errorMessage = 'no_results';

            return;
        }

        $this->result = $result;
        $this->resultType = $type;
    }

    /**
     * Dispatch search-completed event with coordinates when result has property data.
     * This allows the MapPanel component to update its position.
     */
    protected function dispatchSearchCompleted(): void
    {
        if (! $this->result || ! $this->resultType) {
            return;
        }

        $lat = $this->result['property']['latitude'] ?? $this->result['property']['lat'] ?? null;
        $lng = $this->result['property']['longitude'] ?? $this->result['property']['lng'] ?? null;

        if ($lat && $lng) {
            $this->dispatch('search-completed', lat: $lat, lng: $lng, type: $this->resultType);
        }
    }

    public function render()
    {
        return view('metis::livewire.search');
    }
}
