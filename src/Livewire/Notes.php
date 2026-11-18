<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Locked;
use Livewire\Component;
use TheFountainhead\Metis\Livewire\Concerns\HarEgetElement;
use TheFountainhead\Metis\Services\QuotaExceededException;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Brugerens egne noter på et selskab eller en ejendom. Kun synlige for brugeren selv.
 */
class Notes extends Component
{
    use HarEgetElement;

    public const MAKS_TEGN = 5000;

    #[Locked]
    public string $type;

    #[Locked]
    public string $query;

    /**
     * Låst (som `retterId`): kun serveren skriver noterne, så en note her er en, brugeren har fået vist.
     *
     * @var list<array{id: int, body: string, created_at: ?string}>|null null = ikke hentet, eller hentningen fejlede
     */
    #[Locked]
    public ?array $noter = null;

    public string $tekst = '';

    #[Locked]
    public ?int $retterId = null;

    public string $retterTekst = '';

    public ?string $fejl = null;

    public function mount(string $type, string $query): void
    {
        $this->type = $type;
        $this->query = $query;
    }

    public function hent(): void
    {
        $this->fejl = null;
        $element = $this->gyldigtElement();
        if ($element === null) {
            return;
        }

        $svar = app(RegistryApi::class)->egneData('get', '/v1/notes', $element);
        if (isset($svar['error']) || ! is_array($svar['data'] ?? null)) {
            // En fejl er ikke "ingen noter".
            $this->noter = null;
            $this->fejl = __('Kunne ikke hente dine noter. Prøv igen.');

            return;
        }

        $this->noter = array_values(array_map(fn ($n) => [
            'id' => (int) $n['id'],
            'body' => (string) ($n['body'] ?? ''),
            'created_at' => is_string($n['created_at'] ?? null) ? $n['created_at'] : null,
        ], array_filter($svar['data'], fn ($n) => is_array($n) && is_numeric($n['id'] ?? null))));
    }

    public function gem(): void
    {
        $this->fejl = null;
        $element = $this->gyldigtElement();
        $tekst = trim($this->tekst);
        if ($element === null || $tekst === '') {
            return;
        }
        if (mb_strlen($tekst) > self::MAKS_TEGN) {
            $this->fejl = __('Noten er for lang (højst :n tegn).', ['n' => number_format(self::MAKS_TEGN, 0, ',', '.')]);

            return;
        }

        $svar = app(RegistryApi::class)->egneData('post', '/v1/notes', $element + ['body' => $tekst]);
        if (isset($svar['error'])) {
            $this->fejl = $svar['message'] ?? __('Kunne ikke gemme noten. Prøv igen.');

            return;
        }

        $this->tekst = '';
        $this->hent();
    }

    public function ret(int $id): void
    {
        $note = $this->egenNote($id);
        if ($note !== null) {
            $this->retterId = $id;
            $this->retterTekst = $note['body'];
        }
    }

    public function fortryd(): void
    {
        $this->reset(['retterId', 'retterTekst']);
    }

    public function gemRettelse(): void
    {
        $this->fejl = null;
        $tekst = trim($this->retterTekst);
        if ($this->retterId === null || $this->egenNote($this->retterId) === null || $tekst === '') {
            return;
        }
        if (mb_strlen($tekst) > self::MAKS_TEGN) {
            $this->fejl = __('Noten er for lang (højst :n tegn).', ['n' => number_format(self::MAKS_TEGN, 0, ',', '.')]);

            return;
        }

        $svar = app(RegistryApi::class)->egneData('patch', "/v1/notes/{$this->retterId}", ['body' => $tekst]);
        if (isset($svar['error']) && ($svar['status'] ?? null) !== 404) {
            $this->fejl = $svar['message'] ?? __('Kunne ikke gemme noten. Prøv igen.');

            return;
        }

        $this->fortryd();
        $this->hent();
    }

    public function slet(int $id): void
    {
        $this->fejl = null;
        if ($this->egenNote($id) === null) {
            return;
        }

        $svar = app(RegistryApi::class)->egneData('delete', "/v1/notes/{$id}");
        if (isset($svar['error']) && ($svar['status'] ?? null) !== 404) {
            $this->fejl = __('Kunne ikke slette noten. Prøv igen.');

            return;
        }

        $this->hent();
    }

    /**
     * Kun en note brugeren har fået vist på DENNE side kan rettes eller slettes herfra, og kun med adgang.
     *
     * @return array{id: int, body: string, created_at: ?string}|null
     */
    private function egenNote(int $id): ?array
    {
        if (! $this->maaBruge()) {
            return null;
        }

        return collect($this->noter ?? [])->firstWhere('id', $id);
    }

    /** @return array{item_type: string, item_value: string}|null */
    private function gyldigtElement(): ?array
    {
        if (! $this->maaBruge()) {
            return null;
        }

        try {
            return $this->element();
        } catch (QuotaExceededException) {
            return null;
        }
    }

    public function render()
    {
        return view('metis::livewire.notes');
    }
}
