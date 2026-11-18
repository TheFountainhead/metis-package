<?php

namespace TheFountainhead\Metis\Livewire\Concerns;

use TheFountainhead\Metis\Services\LookupAccess;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Fælles for liste-knappen og noterne: hvilket element (ejendom eller selskab) siden handler om, set fra
 * registry-api. Et selskab er sit CVR-nummer; en ejendom er matrikel_id fra adresseanalysen.
 *
 * Personer er bevidst ikke et element (persondata kræver en særskilt beslutning).
 */
trait HarEgetElement
{
    public function maaBruge(): bool
    {
        return app(LookupAccess::class)->kanFoelge();
    }

    /**
     * @return array{item_type: string, item_value: string}|null null = siden har intet element vi kan gemme på
     */
    protected function element(): ?array
    {
        if ($this->type === 'cvr') {
            $cvr = preg_replace('/\D/', '', $this->query);

            return strlen($cvr) === 8 ? ['item_type' => 'company', 'item_value' => $cvr] : null;
        }

        if ($this->type === 'address') {
            // Samme opslag som siden selv har lavet (cachet); kaster kvote-undtagelsen videre til kalderen.
            $analyse = app(RegistryApi::class)->resolveAddressAnalysis($this->query);
            $id = is_array($analyse) ? ($analyse['property']['matrikel_id'] ?? null) : null;

            return is_scalar($id) && strlen((string) $id) >= 3 && strlen((string) $id) <= 64
                ? ['item_type' => 'property', 'item_value' => (string) $id]
                : null;
        }

        return null;
    }
}
