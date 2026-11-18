<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use TheFountainhead\Metis\Livewire\Concerns\HarEgetElement;
use TheFountainhead\Metis\Services\QuotaExceededException;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * "Liste"-knappen på selskabs- og ejendomssiden: sæt det, siden handler om, på en af brugerens egne lister.
 *
 * Henter intet i mount(): status hentes af hent() via wire:init, så siden ikke venter. Ikke lazy, fordi
 * #[Locked] + lazy brækker rehydreringen (samme valg som FollowButton).
 */
class ListButton extends Component
{
    use HarEgetElement;

    #[Locked]
    public string $type;

    #[Locked]
    public string $query;

    #[Locked]
    public string $label = '';

    /** @var list<array{id: int, name: string, contains: bool}>|null null = ikke hentet, eller hentningen fejlede */
    public ?array $lister = null;

    public string $nytNavn = '';

    public ?string $fejl = null;

    public function mount(string $type, string $query, string $label = ''): void
    {
        $this->type = $type;
        $this->query = $query;
        $this->label = mb_substr($label !== '' ? $label : $query, 0, 200);
    }

    public function hent(): void
    {
        $this->fejl = null;
        $element = $this->gyldigtElement();
        if ($element === null) {
            return;
        }

        $svar = app(RegistryApi::class)->egneData('get', '/v1/lists', $element);
        if (isset($svar['error']) || ! is_array($svar['data'] ?? null)) {
            // Ukendt er ikke "står ikke på nogen liste": lad listen være uhentet og sig det.
            $this->lister = null;
            $this->fejl = __('Kunne ikke hente dine lister. Prøv igen.');

            return;
        }

        $this->lister = array_values(array_map(fn ($l) => [
            'id' => (int) ($l['id'] ?? 0),
            'name' => (string) ($l['name'] ?? ''),
            'contains' => ($l['contains'] ?? false) === true,
        ], array_filter($svar['data'], fn ($l) => is_array($l) && is_numeric($l['id'] ?? null))));
    }

    /** Sæt på listen, eller tag af den, hvis elementet allerede står der. */
    public function skift(int $listeId): void
    {
        $this->fejl = null;
        $element = $this->gyldigtElement();
        if ($element === null) {
            return;
        }
        if ($this->lister === null) {
            $this->hent();
        }
        // Kun lister brugeren selv har fået vist: klienten kan ikke pege på et vilkårligt id.
        $liste = collect($this->lister ?? [])->firstWhere('id', $listeId);
        if ($liste === null) {
            $this->fejl ??= __('Listen findes ikke længere.');

            return;
        }

        $api = app(RegistryApi::class);
        if ($liste['contains']) {
            $detaljer = $api->egneData('get', "/v1/lists/{$listeId}");
            $fundet = collect(is_array($detaljer['data']['items'] ?? null) ? $detaljer['data']['items'] : [])
                ->first(fn ($i) => is_array($i) && ($i['item_type'] ?? null) === $element['item_type'] && (string) ($i['item_value'] ?? '') === $element['item_value']);
            $svar = $fundet ? $api->egneData('delete', "/v1/lists/{$listeId}/items/".(int) $fundet['id']) : $detaljer;
        } else {
            $svar = $api->egneData('post', "/v1/lists/{$listeId}/items", $element + ['display_label' => $this->label]);
        }

        // 404 = listen eller elementet er væk (fx fjernet i en anden fane): hent den rigtige tilstand.
        if (isset($svar['error']) && ($svar['status'] ?? null) !== 404) {
            $this->fejl = $svar['message'] ?? __('Kunne ikke gemme ændringen. Prøv igen.');

            return;
        }

        $this->hent();
    }

    /** Opret en ny liste og sæt elementet på den. */
    public function opret(): void
    {
        $this->fejl = null;
        $element = $this->gyldigtElement();
        $navn = trim($this->nytNavn);
        if ($element === null || $navn === '') {
            return;
        }

        $api = app(RegistryApi::class);
        $svar = $api->egneData('post', '/v1/lists', ['name' => mb_substr($navn, 0, 80)]);
        if (isset($svar['error']) || ! is_numeric($svar['data']['id'] ?? null)) {
            $this->fejl = $svar['message'] ?? __('Kunne ikke oprette listen. Prøv igen.');

            return;
        }

        $tilfoej = $api->egneData('post', '/v1/lists/'.(int) $svar['data']['id'].'/items', $element + ['display_label' => $this->label]);
        if (isset($tilfoej['error'])) {
            $this->fejl = __('Listen er oprettet, men kunne ikke få tilføjet denne side. Prøv igen.');
        }

        $this->nytNavn = '';
        $this->hent();
    }

    /**
     * 🚨 Gaten sidder HER, ikke i mount(): alle metoderne er offentlige over /livewire/update.
     *
     * @return array{item_type: string, item_value: string}|null
     */
    private function gyldigtElement(): ?array
    {
        if (! $this->maaBruge()) {
            return null;
        }

        try {
            $element = $this->element();
        } catch (QuotaExceededException) {
            $element = null;
        }
        if ($element === null) {
            $this->fejl = __('Denne side kan ikke sættes på en liste.');
        }

        return $element;
    }

    public function antalValgt(): int
    {
        return count(array_filter($this->lister ?? [], fn ($l) => $l['contains']));
    }

    public function render()
    {
        return view('metis::livewire.list-button');
    }
}
