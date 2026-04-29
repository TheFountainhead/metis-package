<?php

namespace TheFountainhead\Metis\Livewire;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
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

    public array $suggestions = [];

    /**
     * Type-first search mode. When set, search is locked to that type
     * (no SearchDetector ambiguity). Empty = show type-selection screen.
     * Values: '', 'person', 'company', 'address'.
     */
    public string $searchMode = '';

    public function setSearchMode(string $mode): void
    {
        if (! in_array($mode, ['', 'person', 'company', 'address'], true)) {
            return;
        }
        $this->searchMode = $mode;
        $this->query = '';
        $this->reset(['result', 'resultType', 'error', 'errorMessage', 'suggestions', 'cprBlocked', 'rateLimited']);
    }

    public function mount(): void
    {
        $allChips = [
            // Person → selskaber + ejendomme
            ['label' => 'Hvilke selskaber er Lars Larsen med i?', 'query' => 'Lars Larsen'],
            ['label' => 'Hvad ejer Fritz Schur?', 'query' => 'Fritz Schur'],
            ['label' => 'Alle roller for Niels B. Christiansen', 'query' => 'Niels B. Christiansen'],

            // Virksomhed → ejendomme + personer + struktur
            ['label' => 'Hvad ejer Jeudan A/S?', 'query' => 'Jeudan A/S'],
            ['label' => 'Hvem sidder i bestyrelsen hos Mærsk?', 'query' => 'A.P. Møller - Mærsk A/S'],
            ['label' => 'Carlsbergs selskabsstruktur', 'query' => 'Carlsberg A/S'],

            // Adresse → ejer + virksomheder + vurdering
            ['label' => 'Hvem ejer Bredgade 40?', 'query' => 'Bredgade 40, 1260 København'],
            ['label' => 'Virksomheder på Nyhavn 71', 'query' => 'Nyhavn 71, 1051 København'],
            ['label' => 'Vurdering af Amagertorv 15', 'query' => 'Amagertorv 15, 1160 København'],

            // CVR → fuld analyse
            ['label' => 'Ejendomme bag CVR 56811913', 'query' => '56811913'],
            ['label' => 'Alle detaljer om CVR 10007127', 'query' => '10007127'],
        ];

        $this->chips = collect($allChips)->random(4)->values()->all();

        // Handle ?mode= on page load (type-first navigation from sidebar)
        $mode = request()->query('mode');
        if (in_array($mode, ['person', 'company', 'address'], true)) {
            $this->searchMode = $mode;
        }

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

    public string $suggestionType = '';

    public function updatedQuery(): void
    {
        $q = trim($this->query);
        $this->suggestions = [];
        $this->suggestionType = '';

        if (strlen($q) < 3) {
            return;
        }

        // Type-first mode: locked autocomplete behaviour
        if ($this->searchMode === 'address') {
            $this->suggestionType = 'address';
            $this->suggestions = rescue(fn () => app(RegistryApi::class)->addressAutocomplete($q, 5), []) ?? [];
            return;
        }

        if ($this->searchMode === 'company' && strlen($q) >= 4) {
            $results = rescue(fn () => app(RegistryApi::class)->searchByName($q), []) ?? [];
            if (! empty($results)) {
                $this->suggestionType = 'company';
                $this->suggestions = collect($results)->take(5)->map(fn ($c) => [
                    'tekst' => ($c['name'] ?? '').' · CVR '.($c['cvr'] ?? ''),
                    'cvr' => $c['cvr'] ?? '',
                    'name' => $c['name'] ?? '',
                ])->all();
            }
            return;
        }

        if ($this->searchMode === 'person') {
            // No person-autocomplete API. User types full name + presses Enter.
            return;
        }

        // Free-text mode (no locked type) — use detector
        $detector = new SearchDetector;
        $type = $detector->detect($q);

        if ($type === 'address') {
            $this->suggestionType = 'address';
            $this->suggestions = rescue(fn () => app(RegistryApi::class)->addressAutocomplete($q, 5), []) ?? [];
        } elseif (in_array($type, ['company_name', 'name']) && strlen($q) >= 4) {
            $results = rescue(fn () => app(RegistryApi::class)->searchByName($q), []) ?? [];
            if (! empty($results)) {
                $this->suggestionType = 'company';
                $this->suggestions = collect($results)->take(5)->map(fn ($c) => [
                    'tekst' => ($c['name'] ?? '').' · CVR '.($c['cvr'] ?? ''),
                    'cvr' => $c['cvr'] ?? '',
                    'name' => $c['name'] ?? '',
                ])->all();
            }
        }
    }

    public function selectSuggestion(string $text): void
    {
        // For company suggestions, use CVR directly
        if ($this->suggestionType === 'company') {
            $match = collect($this->suggestions)->first(fn ($s) => $s['tekst'] === $text);
            if ($match && ! empty($match['cvr'])) {
                $this->query = $match['cvr'];
                $this->suggestions = [];
                $this->search();

                return;
            }
        }

        $this->query = $text;
        $this->suggestions = [];
        $this->search();
    }

    public function search(): void
    {
        // Preserve suggestions until search succeeds — keep autocomplete-list
        // visible as fallback if Enter-search returns empty (UX: don't strand
        // user with "no results" when we already showed them 5 matches).
        $previousSuggestions = $this->suggestions;
        $previousSuggestionType = $this->suggestionType;
        $this->suggestions = [];
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

        // Type-first mode: bypass SearchDetector
        if ($this->searchMode !== '') {
            $type = match ($this->searchMode) {
                'person' => 'name',
                'company' => 'company_name',
                'address' => 'address',
                default => 'name',
            };
        } else {
            $detector = new SearchDetector;
            $type = $detector->detect($query);
        }

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

        // For CVR/address, show full sections inline (no redirect)
        if (in_array($type, ['cvr', 'address'])) {
            $this->resultType = $type;
            $this->logLookup($type, $query, isCrossReference: false);
            $this->dispatch('update-url', query: $query, type: $type);
            $this->dispatch('scroll-top');

            return;
        }

        $this->loading = true;
        $this->performSearch($type, $query);
        $this->loading = false;

        // Log only successful searches
        if ($this->result) {
            $this->logLookup($type, $query, isCrossReference: false);
            $this->dispatch('update-url', query: $query, type: $type);
            $this->dispatchSearchCompleted();
        } elseif ($this->error && $this->errorMessage === 'no_results' && ! empty($previousSuggestions)) {
            // Restore autocomplete-suggestions so user has clickable fallback
            $this->suggestions = $previousSuggestions;
            $this->suggestionType = $previousSuggestionType;
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

    public function clearSearch(): void
    {
        $this->reset(['query', 'result', 'resultType', 'error', 'errorMessage', 'cprBlocked', 'rateLimited']);
        $this->dispatch('scroll-top');
        $this->js("history.pushState(null, '', '/'); document.title = 'Metis';");
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

        // CVR/address → show sections inline
        if (in_array($type, ['cvr', 'address'])) {
            $this->resultType = $type;
            $this->logLookup($type, $value, isCrossReference: true);
            $this->dispatch('update-url', query: $value, type: $type);
            $this->dispatch('scroll-top');

            return;
        }

        $this->loading = true;
        $this->performSearch($type, $value);
        $this->loading = false;

        if ($this->result) {
            $this->logLookup($type, $value, isCrossReference: true);
            $this->dispatchSearchCompleted();
        }

        if ($type !== 'cpr') {
            $this->dispatch('update-url', query: $value, type: $type);
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
        rescue(fn () => MetisLookup::create([
            'session_id' => session()->getId(),
            'email' => session('metis_verified_email'),
            'search_type' => $type,
            'search_term' => $query,
            'ip_address' => request()->ip(),
            'is_cross_reference' => $isCrossReference,
        ]));

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

        // Type-first lock: when user picked a mode, only search that type.
        // Eliminates 'name'-fallback that returns BOTH persons + companies.
        if ($this->searchMode === 'person') {
            $result = ['persons' => $api->searchPersonByName($query)];
        } elseif ($this->searchMode === 'company') {
            $result = ['companies' => $api->searchByName($query)];
        } elseif ($this->searchMode === 'address') {
            $result = $api->fetchPropertyByAddress($query);
        } else {
            $result = match ($type) {
                'cvr' => $api->fetchCompany($query),
                'company_name' => ['companies' => $api->searchByName($query)],
                'name' => ['persons' => $api->searchPersonByName($query), 'companies' => $api->searchByName($query)],
                'address' => $api->fetchPropertyByAddress($query),
                default => null,
            };
        }

        if ($result === null || isset($result['error'])) {
            $this->error = true;
            $this->errorMessage = 'api_error';

            return;
        }

        // Strip nested error responses (e.g. persons returning ['error' => ...])
        if (is_array($result)) {
            foreach ($result as $key => $value) {
                if (is_array($value) && isset($value['error'])) {
                    $result[$key] = [];
                }
            }
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
        $view = view('metis::livewire.search');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
