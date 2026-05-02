<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Toggle follow-state for a property (matrikel_id) or company (CVR).
 *
 * Mounts by checking watch-state via batch API call. Click toggles
 * follow → POST /v2/watchlists or DELETE /v2/watchlists/{id}.
 *
 * Embeds in /lookup/address/* (property) and /lookup/cvr/* (company).
 */
class FollowButton extends Component
{
    public string $watchType;
    public string $watchValue;
    public string $displayLabel = '';

    public bool $isFollowed = false;
    public ?int $watchlistId = null;
    public bool $loading = false;
    public ?string $error = null;

    public function mount(string $watchType, string $watchValue, string $displayLabel = ''): void
    {
        $this->watchType = $watchType;
        $this->watchValue = $watchValue;
        $this->displayLabel = $displayLabel;

        if (! $this->watchValue) {
            return;
        }

        try {
            $check = app(RegistryApi::class)->checkBatch([
                ['type' => $this->watchType, 'value' => $this->watchValue],
            ]);
            $first = data_get($check, 'data.0', []);
            $this->isFollowed = (bool) ($first['is_followed'] ?? false);
            $this->watchlistId = $first['watchlist_id'] ?? null;
        } catch (\Throwable $e) {
            // Silent fail on mount — show "not followed" state and let toggle retry
        }
    }

    public function toggle(): void
    {
        $this->loading = true;
        $this->error = null;

        try {
            $api = app(RegistryApi::class);

            if ($this->isFollowed && $this->watchlistId) {
                $api->deleteWatchlist($this->watchlistId);
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
                    $this->error = 'Kunne ikke tilføje til watchlist.';
                    return;
                }

                $this->watchlistId = data_get($resp, 'data.id');
                $this->isFollowed = true;
            }
        } catch (\Throwable $e) {
            $this->error = 'Kunne ikke ændre følge-status. Prøv igen.';
        } finally {
            $this->loading = false;
        }
    }

    public function render()
    {
        return view('metis::livewire.follow-button');
    }
}
