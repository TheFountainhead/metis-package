<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Detail-view for a single alert (mortgage_change diff).
 *
 * Shown at /alerts/{id} — linked from AlertsInbox row-click. Renders
 * before/after pantebrev-state side-by-side for principal/creditor changes,
 * single-column for new/new_lien/removed.
 */
class AlertDetail extends Component
{
    public int $alertId;
    public ?array $alert = null;
    public ?string $error = null;
    public bool $loading = true;

    public function mount(int $id): void
    {
        $this->alertId = $id;
        $this->fetch();
    }

    public function fetch(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $alert = app(RegistryApi::class)->getAlert($this->alertId);

            if ($alert === null) {
                $this->error = __('Alert ikke fundet eller ingen adgang.');
                $this->alert = null;
            } else {
                $this->alert = $alert;
            }
        } catch (\Throwable $e) {
            $this->error = __('Søgetjenesten er midlertidigt utilgængelig.');
            $this->alert = null;
        } finally {
            $this->loading = false;
        }
    }

    public function markRead(): void
    {
        try {
            app(RegistryApi::class)->markAlertRead($this->alertId);
            $this->fetch();
        } catch (\Throwable $e) {
            // Silent — UI remains stale, user can refresh
        }
    }

    public function render()
    {
        $view = view('metis::livewire.alert-detail');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
