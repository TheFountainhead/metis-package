<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use TheFountainhead\Metis\Livewire\Concerns\GatesLookups;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * "Spørg om noget" — aggregerede spørgsmål om en population af ejendomme.
 *
 * 🔑 Et ANDET produkt end opslaget. Søgefeltet finder én ting man kender
 * navnet på; det her afgrænser en mængde med kriterier og tæller. Frederiks
 * idé 9/8: *"hvor mange erhvervsejendomme i et bestemt postnummer har lån med
 * en rente på over 10 %?"*
 *
 * 🚨 SVARET VISER ALTID SIN DÆKNING. Målt på prod 10/8: kun ~70 % af
 * pantebrevene i et typisk postnummer har en kendt rentesats; resten er
 * `variabel`/`kontantlaan`, som HAR en rente vi ikke kender. Et præcist tal
 * uden det forbehold kan føre til en forkert kreditbeslutning.
 *
 * 🪤 OG DEN VISER HVAD DEN FORSTOD. "over 10" og "under 10" giver begge et
 * plausibelt tal, så en forveksling ville ellers være usynlig for den der
 * læser svaret.
 */
class Analytics extends Component
{
    use GatesLookups;

    /**
     * 🪤 `#[Url]` er BRUGERINPUT — det kommer fra query-strengen og kan sættes
     * af hvem som helst. Værdien sendes derfor gennem samme validering som et
     * felt fra formularen, aldrig direkte videre.
     */
    #[Url(as: 'q', except: '')]
    public string $spoergsmaal = '';

    public bool $indlaeser = false;

    public bool $gated = false;

    /** @var array<string, mixed>|null */
    public ?array $svar = null;

    public ?string $fejl = null;

    /** @var array<int, string> */
    public array $eksempler = [
        'Hvor mange erhvervsejendomme i 2100 har lån med en rente over 10 %?',
        'Erhvervsejendomme i 8000 med rente under 3 %',
        'Udlejningsejendomme i 2200',
    ];

    public function mount(): void
    {
        if ($this->spoergsmaal !== '') {
            $this->spoerg();
        }
    }

    public function brugEksempel(string $tekst): void
    {
        $this->spoergsmaal = $tekst;
        $this->spoerg();
    }

    public function spoerg(): void
    {
        $this->reset(['svar', 'fejl', 'gated']);

        $tekst = trim($this->spoergsmaal);

        if (mb_strlen($tekst) < 3) {
            $this->fejl = __('Skriv et spørgsmål.');

            return;
        }

        // 🚨 Samme kvote-gate som opslagene. Et analyse-spørgsmål kan afdække
        // en hel population og er derfor MERE værdifuldt end ét opslag —
        // det må ikke være vejen udenom betalingen.
        if ($this->skalGates()) {
            $this->gated = true;
            $this->dispatch('show-email-gate');

            return;
        }

        $this->indlaeser = true;

        $svar = rescue(fn () => app(RegistryApi::class)->askAnalytics($tekst), null, false);

        $this->indlaeser = false;

        if (! is_array($svar) || isset($svar['error'])) {
            // 🪤 Vis API'ets EGEN forklaring når den findes: "jeg forstod ingen
            // kriterier" er langt mere brugbart end "der opstod en fejl".
            $this->fejl = data_get($svar, 'error.message')
                ?? __('Jeg kunne ikke besvare spørgsmålet. Prøv at omformulere det.');

            $this->svar = ['uforstaaet' => data_get($svar, 'error.details.uforstaaet', [])];

            return;
        }

        $this->svar = [
            'antal' => data_get($svar, 'data.antal', 0),
            'ejendomme' => data_get($svar, 'data.ejendomme', []),
            'vist' => data_get($svar, 'data.vist', 0),
            'forstaaet' => data_get($svar, 'meta.forstaaet', []),
            'uforstaaet' => data_get($svar, 'meta.uforstaaet', []),
            'daekning' => data_get($svar, 'meta.daekning', []),
        ];

        $this->taelOpslag();
    }

    public function render()
    {
        $view = view('metis::livewire.analytics');

        return config('metis.mode') === 'standalone'
            ? $view->layout('metis::layouts.standalone')
            : $view;
    }
}
