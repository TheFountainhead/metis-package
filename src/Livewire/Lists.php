<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use TheFountainhead\Metis\Livewire\Concerns\HarEgetElement;
use TheFountainhead\Metis\Services\RegistryApi;
use TheFountainhead\Metis\View\Components\MetisLink;

/**
 * Brugerens egne lister: oversigten (/lister) og én liste (/lister/{id}).
 *
 * Kun brugerens egne valg. Ingen søgning på tværs af registret.
 */
class Lists extends Component
{
    use HarEgetElement;

    #[Locked]
    public ?int $listeId = null;

    /** @var list<array{id: int, name: string, items_count: int}>|null */
    #[Locked]
    public ?array $lister = null;

    /**
     * Låst: kun serveren skriver listen (også elementernes link), så et element her er et, brugeren har fået vist.
     *
     * @var array{id: int, name: string, items: list<array<string, mixed>>}|null
     */
    #[Locked]
    public ?array $liste = null;

    public string $nytNavn = '';

    public string $omdoebTil = '';

    public bool $omdoeber = false;

    public ?string $fejl = null;

    public bool $findesIkke = false;

    public function mount(?int $id = null): void
    {
        $this->listeId = $id;
        if ($this->maaBruge()) {
            $this->hent();
        }
    }

    public function hent(): void
    {
        // 🚨 Gaten skal sidde HER: `hent` er offentlig over /livewire/update.
        if (! $this->maaBruge()) {
            return;
        }
        $this->fejl = null;
        $api = app(RegistryApi::class);

        if ($this->listeId !== null) {
            $svar = $api->egneData('get', "/v1/lists/{$this->listeId}");
            if (($svar['status'] ?? null) === 404) {
                $this->findesIkke = true;
                $this->liste = null;

                return;
            }
            if (isset($svar['error']) || ! is_array($svar['data']['items'] ?? null)) {
                $this->liste = null;
                $this->fejl = __('Kunne ikke hente listen. Prøv igen.');

                return;
            }
            $this->liste = [
                'id' => (int) $svar['data']['id'],
                'name' => (string) ($svar['data']['name'] ?? ''),
                'items' => array_values(array_map(fn ($i) => [
                    'id' => (int) $i['id'],
                    'item_type' => (string) ($i['item_type'] ?? ''),
                    'item_value' => (string) ($i['item_value'] ?? ''),
                    'display_label' => is_string($i['display_label'] ?? null) ? $i['display_label'] : '',
                    'url' => $this->urlFor($i),
                ], array_filter($svar['data']['items'], fn ($i) => is_array($i) && is_numeric($i['id'] ?? null)))),
            ];

            return;
        }

        $svar = $api->egneData('get', '/v1/lists');
        if (isset($svar['error']) || ! is_array($svar['data'] ?? null)) {
            // En fejl er ikke "du har ingen lister".
            $this->lister = null;
            $this->fejl = __('Kunne ikke hente dine lister. Prøv igen.');

            return;
        }
        $this->lister = array_values(array_map(fn ($l) => [
            'id' => (int) $l['id'],
            'name' => (string) ($l['name'] ?? ''),
            'items_count' => (int) ($l['items_count'] ?? 0),
        ], array_filter($svar['data'], fn ($l) => is_array($l) && is_numeric($l['id'] ?? null))));
    }

    public function opret(): void
    {
        $navn = trim($this->nytNavn);
        if (! $this->maaBruge() || $this->listeId !== null || $navn === '') {
            return;
        }

        $svar = app(RegistryApi::class)->egneData('post', '/v1/lists', ['name' => mb_substr($navn, 0, 80)]);
        if (isset($svar['error'])) {
            $this->fejl = $svar['message'] ?? __('Kunne ikke oprette listen. Prøv igen.');

            return;
        }

        $this->nytNavn = '';
        $this->hent();
    }

    public function startOmdoeb(): void
    {
        if ($this->liste !== null) {
            $this->omdoeber = true;
            $this->omdoebTil = $this->liste['name'];
        }
    }

    public function omdoeb(): void
    {
        $navn = trim($this->omdoebTil);
        if (! $this->maaBruge() || $this->listeId === null || $navn === '') {
            return;
        }

        $svar = app(RegistryApi::class)->egneData('patch', "/v1/lists/{$this->listeId}", ['name' => mb_substr($navn, 0, 80)]);
        if (isset($svar['error'])) {
            $this->fejl = $svar['message'] ?? __('Kunne ikke omdøbe listen. Prøv igen.');

            return;
        }

        $this->omdoeber = false;
        $this->hent();
    }

    public function fjern(int $elementId): void
    {
        // Kun et element brugeren har fået vist på denne liste.
        if (! $this->maaBruge() || $this->listeId === null || collect($this->liste['items'] ?? [])->firstWhere('id', $elementId) === null) {
            return;
        }

        $svar = app(RegistryApi::class)->egneData('delete', "/v1/lists/{$this->listeId}/items/{$elementId}");
        if (isset($svar['error']) && ($svar['status'] ?? null) !== 404) {
            $this->fejl = __('Kunne ikke fjerne fra listen. Prøv igen.');

            return;
        }

        $this->hent();
    }

    public function sletListe()
    {
        if (! $this->maaBruge() || $this->listeId === null) {
            return null;
        }

        $svar = app(RegistryApi::class)->egneData('delete', "/v1/lists/{$this->listeId}");
        if (isset($svar['error']) && ($svar['status'] ?? null) !== 404) {
            $this->fejl = __('Kunne ikke slette listen. Prøv igen.');

            return null;
        }

        // Ruten findes kun, hvor pakkens web-ruter er indlæst; ellers bliv på siden, som nu siger at listen er væk.
        if (! \Illuminate\Support\Facades\Route::has('metis.lists')) {
            $this->findesIkke = true;
            $this->liste = null;

            return null;
        }

        return $this->redirectRoute('metis.lists');
    }

    /**
     * Link tilbage til siden for et element. Et selskab slås op på sit CVR; en ejendom på den adresse, den
     * blev gemt med (matrikel_id kan ikke slås op direkte). Uden brugbar adresse: intet link.
     *
     * @param  array<string, mixed>  $element
     */
    private function urlFor(array $element): ?string
    {
        return match ($element['item_type'] ?? null) {
            'company' => MetisLink::urlFor('cvr', (string) ($element['item_value'] ?? '')),
            'property' => filled($element['display_label'] ?? null) ? MetisLink::urlFor('address', (string) $element['display_label']) : null,
            default => null,
        };
    }

    public function render()
    {
        $view = view('metis::livewire.lists');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
