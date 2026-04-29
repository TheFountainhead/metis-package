<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use Livewire\WithPagination;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Inbox-side for debt-alerts.
 *
 * Lazy-load på mount, filter på unread/priority, mark-read inline.
 * Empty state med 2 CTAs (Søg ejendom / Søg CVR).
 */
class AlertsInbox extends Component
{
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
        try {
            $resp = app(RegistryApi::class)->listWatchlists();
            $this->watchlists = $resp['data'] ?? [];
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

        session(['metis_user_token' => $token]);
        $this->tokenInput = '';
        $this->tokenError = false;
        $this->fetch();
        $this->loadWatchlists();
    }

    public function clearToken(): void
    {
        session()->forget('metis_user_token');
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
        $this->loading = true;
        $this->error = null;

        try {
            $this->response = app(RegistryApi::class)->listAlerts(
                unreadOnly: $this->unreadOnly,
                priority: $this->priority,
                page: $this->getPage(),
            );

            if (isset($this->response['error'])) {
                $this->error = 'Kunne ikke hente alerts.';
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
