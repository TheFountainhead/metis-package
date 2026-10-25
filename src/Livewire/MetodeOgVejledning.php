<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Component;
use TheFountainhead\Metis\Services\LookupAccess;

/**
 * Metode og vejledning: de skrevne klassifikationsregler bag segmenteringen,
 * og en kort brugervejledning (ERST-udbuddet: skriftlig dokumentation af
 * reglerne og undervisning som "onboarding eller selvstudiemateriale").
 *
 * 🔑 Teksten er afledt af registry-api's KODE, ikke skrevet fra hukommelsen:
 * CompanySegmentation::applyFilters, CvrStatus, VerticalMapper, Fintech,
 * ImporterFintechRegistreCommand, KlassificerFormaalCommand, InvestorClassifier,
 * ByggInvestortyperCommand og FundingHistoryService (origin/main 25/10-2026).
 * Aendres en regel dér, skal siden her aendres med.
 *
 * Samme adgang som segmenteringen (`harPilotAdgang`), saa siden og det den
 * beskriver aabner og lukker sammen.
 */
class MetodeOgVejledning extends Component
{
    /**
     * Branchekode-praefikser pr. vertikal, som i registry-api's
     * VerticalMapper::MAPPING. Det laengste praefiks vinder.
     *
     * 🪤 Noeglerne SKAL vaere de samme som CompanySegmentation::VERTIKALER;
     * en test holder dem sammen, saa en ny vertikal ikke mangler her.
     *
     * @var array<string, string>
     */
    public const VERTIKAL_BRANCHER = [
        'software' => '62, 58.2, 63.1, 63.9',
        'telekom' => '61',
        'life-science' => '21, 32.5, 46.46, 72.11',
        'sundhed' => '86',
        'forskning-udvikling' => '72 (undtagen 72.11)',
        'hardware' => '26, 27',
        'industri' => '13, 14, 17, 22, 23, 25, 28, 30',
        'kemi' => '20',
        'foedevarer' => '10, 11',
        'landbrug' => '01, 03',
        'energi' => '35',
        'miljoe' => '36, 37, 38',
        'raastoffer' => '08',
        'finans' => '64, 65, 66',
        'engroshandel' => '46 (undtagen 46.46)',
        'detailhandel' => '47',
        'transport-logistik' => '49, 50, 51, 52, 53',
        'medier' => '58.1, 59, 60',
        'marketing' => '73',
        'raadgivning' => '69, 70, 71, 74',
        'hr' => '78',
        'rejser' => '79',
        'uddannelse' => '85',
        'ejendom' => '68',
        'byggeri' => '41, 42, 43',
        'hotel-restauration' => '55, 56',
        'udlejning-leasing' => '77',
        'sikkerhed' => '80',
        'facility' => '81',
        'forretningsservice' => '82',
        'sport-fritid' => '93',
        'foreninger' => '94',
        'oevrige' => 'Alle andre branchekoder, fx 33, 90, 95 og 96',
    ];

    public function harAdgang(): bool
    {
        return app(LookupAccess::class)->harPilotAdgang();
    }

    public function render()
    {
        $view = view('metis::livewire.metode-og-vejledning')
            ->title('Metode og vejledning — Metis');

        // Samme grund som CompanySegmentation::render: uden layoutet er siden
        // 500 i standalone.
        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
