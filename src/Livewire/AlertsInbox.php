<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use TheFountainhead\Metis\Livewire\Concerns\NormalisererAlerts;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Inbox-side for debt-alerts.
 *
 * Lazy-load på mount, filter på unread/priority, mark-read inline.
 * Empty state med 2 CTAs (Søg ejendom / Søg CVR).
 */
class AlertsInbox extends Component
{
    use NormalisererAlerts;
    use WithPagination;

    public bool $unreadOnly = false;
    public ?string $priority = null;

    public ?array $response = null;
    public ?array $watchlists = null;
    public bool $showWatchlists = false;
    public bool $loading = false;
    public ?string $error = null;

    public string $tokenInput = '';
    public bool $tokenError = false;

    public function mount(): void
    {
        if ($this->hasUserToken()) {
            $this->fetch();
            $this->loadWatchlists();
        }
    }

    public function loadWatchlists(): void
    {
        // 🚨 Offentlig over `/livewire/update`, uanset hvad siden viser. Kun en
        // bekraeftet pilot; ellers ville den delte tenant-noegle svare.
        if (! $this->harPilotAdgang()) {
            $this->watchlists = [];

            return;
        }

        try {
            $resp = app(RegistryApi::class)->listWatchlists();
            $this->watchlists = isset($resp['error']) ? [] : self::normaliserWatchlists($resp);
        } catch (\Throwable $e) {
            $this->watchlists = [];
        }
    }

    public function toggleWatchlists(): void
    {
        $this->showWatchlists = ! $this->showWatchlists;
    }

    public function hasUserToken(): bool
    {
        return ! empty(session('metis_user_token'));
    }

    public function setToken(): void
    {
        $token = trim($this->tokenInput);
        if (! preg_match('/^\d+\|[A-Za-z0-9]+$/', $token)) {
            $this->tokenError = true;
            return;
        }

        // 🪤 Tokenet er IKKE proevet her. Det giver derfor ingen kvote-
        // fritagelse (review V2, se `LookupAccess::erPilot()`): kun dataene
        // registry-api selv udleverer paa tokenet. Flaget fjernes, saa et
        // tidligere verificeret pilot-flag ikke daekker et nyt indtastet token.
        session(['metis_user_token' => $token]);
        session()->forget('metis_pilot_verificeret');
        $this->tokenInput = '';
        $this->tokenError = false;
        $this->fetch();
        $this->loadWatchlists();
    }

    public function clearToken(): void
    {
        // En pilot med kodeord logges helt ud (husk-mig-nøgle + identitet);
        // ellers ville RestorePilotSession lægge tokenet tilbage ved næste request.
        if (session('metis_pilot_account_id')) {
            \TheFountainhead\Metis\Livewire\PilotLogin::logout();
        }

        session()->forget(['metis_user_token', 'metis_pilot_verificeret']);
        $this->response = null;
        $this->watchlists = null;
        $this->showWatchlists = false;
    }

    public function unfollow(int $watchlistId): void
    {
        try {
            app(RegistryApi::class)->deleteWatchlist($watchlistId);
            $this->loadWatchlists();
            $this->fetch();
        } catch (\Throwable $e) {
            // Silent — UI remains stale, user can refresh
        }
    }

    public function updated(string $name): void
    {
        if (in_array($name, ['unreadOnly', 'priority'], true)) {
            $this->resetPage();
            $this->fetch();
        }
    }

    public function fetch(): void
    {
        $this->error = null;

        // 🚨 Samme gate som `loadWatchlists()`: en ikke-pilot faar en tom
        // tilstand og intet kald.
        if (! $this->harPilotAdgang()) {
            $this->response = null;
            $this->loading = false;

            return;
        }

        $this->loading = true;

        try {
            $svar = app(RegistryApi::class)->listAlerts(
                unreadOnly: $this->unreadOnly,
                priority: $this->priority,
                page: $this->getPage(),
            );

            if (isset($svar['error'])) {
                $this->response = null;
                $this->error = 'Kunne ikke hente alerts.';
            } else {
                $this->response = self::normaliserAlertSvar($svar);
            }
        } catch (\Throwable $e) {
            $this->error = 'Søgetjenesten er midlertidigt utilgængelig.';
        } finally {
            $this->loading = false;
        }
    }

    public function markRead(int $alertId): void
    {
        try {
            app(RegistryApi::class)->markAlertRead($alertId);
            $this->fetch();
        } catch (\Throwable $e) {
            // Silent fail — UI state remains stale until next fetch
        }
    }

    public function render()
    {
        $view = view('metis::livewire.alerts-inbox');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
