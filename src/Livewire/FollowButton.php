<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Toggle follow-state for a property (matrikel_id) or company (CVR).
 *
 * Mounts by checking watch-state via batch API call. Click toggles
 * follow → POST /v1/watchlists or DELETE /v1/watchlists/{id}.
 *
 * Embeds in /lookup/address/* (property) and /lookup/cvr/* (company).
 */
class FollowButton extends Component
{
    // Laast: ellers kan klienten saette typen til `cpr` og gemme et CPR som
    // watchlist-raekke i registry-api (review metis-package#195).
    #[Locked]
    public string $watchType;

    #[Locked]
    public string $watchValue;
    public string $displayLabel = '';

    /** null = ukendt (ikke hentet endnu, eller opslaget fejlede) — ALDRIG læst som "ikke fulgt". */
    public ?bool $isFollowed = null;
    public ?int $watchlistId = null;
    public bool $loading = false;
    public ?string $error = null;

    public function mount(string $watchType, string $watchValue, string $displayLabel = ''): void
    {
        $this->watchType = $watchType;
        $this->watchValue = $watchValue;
        $this->displayLabel = $displayLabel;
        // Ingen HTTP her: status hentes af loadStatus() via wire:init, så siden ikke venter.
    }

    public function loadStatus(): void
    {
        if (! $this->watchValue) {
            return;
        }

        try {
            $check = app(RegistryApi::class)->checkBatch([
                ['type' => $this->watchType, 'value' => $this->watchValue],
            ]);
        } catch (\Throwable $e) {
            $check = ['error' => 'exception'];
        }

        // checkBatch kaster ikke ved fejl, men returnerer ['error' => ...]. Et fejlet opslag er
        // UKENDT status — ellers tilbyder knappen "Følg" for et fulgt selskab og laver en dublet.
        $first = data_get($check, 'data.0');
        if (isset($check['error']) || ! is_array($first)) {
            $this->isFollowed = null;
            $this->watchlistId = null;

            return;
        }

        $fulgt = (bool) ($first['is_followed'] ?? false);
        $id = $first['watchlist_id'] ?? null;
        // Fulgt uden id kan ikke afmeldes og må ikke oprettes igen: ukendt.
        $this->isFollowed = $fulgt && $id === null ? null : $fulgt;
        $this->watchlistId = $fulgt ? $id : null;
    }

    public function toggle(): void
    {
        $this->error = null;

        if ($this->isFollowed === null) {
            $this->loadStatus();
            if ($this->isFollowed === null) {
                $this->error = __('Kunne ikke hente følge-status. Prøv igen.');

                return;
            }
        }

        $this->loading = true;

        try {
            $api = app(RegistryApi::class);

            if ($this->isFollowed) {
                // deleteWatchlist() kaster ikke ved afvisning, men returnerer ['error' => ...].
                // 404 = watchen er allerede væk (fx afmeldt i en anden fane): det ER afmeldt.
                $resp = $api->deleteWatchlist((int) $this->watchlistId);
                if (isset($resp['error']) && ($resp['status'] ?? null) !== 404) {
                    $this->error = __('Kunne ikke fjerne fra watchlist.');

                    return;
                }
                $this->isFollowed = false;
                $this->watchlistId = null;
            } else {
                $resp = $api->createWatchlist(
                    $this->watchType,
                    $this->watchValue,
                    $this->displayLabel ?: null,
                    ['new_mortgage', 'amount_changed', 'rate_changed', 'paid_off'],
                );

                if (isset($resp['error'])) {
                    $this->error = __('Kunne ikke tilføje til watchlist.');

                    return;
                }

                $this->watchlistId = data_get($resp, 'data.id');
                $this->isFollowed = true;
            }
        } catch (\Throwable $e) {
            $this->error = __('Kunne ikke ændre følge-status. Prøv igen.');
        } finally {
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('metis::livewire.follow-button');
    }
}
