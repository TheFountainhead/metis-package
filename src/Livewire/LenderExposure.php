<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\Url;
use Livewire\Component;
use TheFountainhead\Metis\Services\LegalName;
use TheFountainhead\Metis\Services\RegistryApi;

/**
 * Baglaens laangiver-opslag: hvor er DENNE laangiver eksponeret?
 *
 * 🎯 Den anden halvdel af underpant-differentiatoren. Fremad viser
 * selskabssiden "hvem har laant penge hertil"; her svarer vi det omvendte —
 * kreditrisiko pr. laangiver.
 *
 * 🚨 En kreditor-soegning kan IKKE det her. Ved et ejerpantebrev er selskabet
 * SELV anfoert som kreditor: paa Akacietorvet staar `AKACIETORVET ApS` paa
 * begge ejerpantebreve, mens Ringkjoebing Landbobank, Draupnir og Omega kun
 * findes i `UnderpantrettighedSamling`. Feltet er offentligt efter
 * tinglysningslovens § 1 a, stk. 1.
 *
 * ⚠️ Forbeholdet fra API'ets `meta.disclaimer` vises ALTID sammen med beloebet.
 * Summen er hvad panthaveren staar ANFOERT for; de kan optraede paa vegne af
 * andre kreditorer. Uden forbeholdet laeses tallet som en paastand om deres
 * balance, og det kan vi ikke staa inde for.
 */
class LenderExposure extends Component
{
    /**
     * 🪤 `#[Url]` er BRUGERINPUT. Vaerdien kommer fra query-strengen og maa
     * behandles som enhver anden ikke-betroet parameter — den valideres derfor
     * som praecis 8 cifre foer den naar API'et.
     */
    #[Url(as: 'cvr')]
    public string $cvr = '';

    public ?array $exposure = null;

    public bool $loading = false;

    public ?string $errorMessage = null;

    public function mount(): void
    {
        if ($this->cvr !== '') {
            $this->search();
        }
    }

    public function search(): void
    {
        $this->reset(['exposure', 'errorMessage']);
        $cvr = preg_replace('/\D/', '', $this->cvr);

        // Formkravet ligger HER, ikke kun i API'et: et 7-cifret CVR ville
        // ellers give et tomt svar der er umuligt at skelne fra "laangiveren
        // har intet pant".
        if (strlen((string) $cvr) !== 8) {
            $this->errorMessage = __('CVR skal være præcis 8 cifre.');

            return;
        }

        $this->cvr = $cvr;
        $this->loading = true;

        $result = app(RegistryApi::class)->fetchLenderExposure($cvr);

        $this->loading = false;

        // 🪤 `null` og `['error' => ...]` er to forskellige udfald, og begge
        // skal fanges. RegistryApi returnerer error-shapen ved baade HTTP-fejl
        // og transport-fejl (timeout/DNS) — se getEnvelope().
        if ($result === null || isset($result['error'])) {
            $this->errorMessage = __('Kunne ikke hente data. Prøv igen.');

            return;
        }

        $this->exposure = $result;
    }

    /** Laangivernavnet i laesbar form, eller CVR som fallback. */
    public function getLenderLabelProperty(): string
    {
        $name = $this->exposure['lender_name'] ?? null;

        return $name ? LegalName::format($name) : $this->cvr;
    }

    /**
     * Eksponeringen samlet pr. ejendom.
     *
     * Raa raekker er pr. (dokument, panthaver). Samme ejendom kan derfor
     * optraede flere gange — det er korrekt for et dokument-view, men
     * uigennemskueligt naar spoergsmaalet er "hvilke ejendomme er stillet som
     * sikkerhed". Her foldes de, og beloebene laegges sammen.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getByPropertyProperty(): array
    {
        $rows = $this->exposure['rows'] ?? [];
        $byAddress = [];

        foreach ($rows as $row) {
            // 🚨 REVIEW-FUND 5/8: noeglen var `bfe|address` UDEN postnummer.
            // To FORSKELLIGE ejendomme paa samme vejnavn i hver sin by (4000
            // vs. 9000), begge uden BFE, foldede derfor til ÉN raekke med
            // beloebene lagt sammen. Verificeret: 2 raekker ind -> 1 ud med
            // 20.000.000 kr.
            //
            // I en kreditvurdering er det en forkert paastand om hvilken
            // sikkerhed der findes. postal_code laa allerede i payloaden og
            // var udeladt uden grund.
            //
            // 🪤 Uden BFE er adresse+postnummer det bedste vi har, men det er
            // stadig en tilnaermelse — to ejendomme paa samme adresse uden BFE
            // kan ikke skelnes. Derfor foldes de KUN naar en BFE findes;
            // ellers staar hver raekke for sig og bevarer sit dokument-id.
            $key = ($row['bfe'] ?? null) !== null
                ? 'bfe:'.$row['bfe']
                : 'doc:'.($row['document'] ?? '').'|'.($row['address'] ?? '').'|'.($row['postal_code'] ?? '');

            $byAddress[$key] ??= [
                'address' => $row['address'] ?? null,
                'postal_code' => $row['postal_code'] ?? null,
                'bfe' => $row['bfe'] ?? null,
                'amount_kr' => 0,
                'documents' => 0,
            ];

            $byAddress[$key]['amount_kr'] += (int) ($row['amount_kr'] ?? 0);
            $byAddress[$key]['documents']++;
        }

        usort($byAddress, fn ($a, $b) => $b['amount_kr'] <=> $a['amount_kr']);

        return array_values($byAddress);
    }

    public function render()
    {
        $view = view('metis::livewire.lender-exposure');

        // Samme betingede layout som DebtSearch/PropertyExplore: pakken koerer
        // baade standalone og indlejret i en vaerts-app.
        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
