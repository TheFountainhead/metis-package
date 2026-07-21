<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

/**
 * "Aktieposter & relationer" — shareholder-relationer (begge retninger).
 *
 * ADSKILT fra CompanyStructure (datterselskaber = legal_owner). Vises MÆRKET
 * som aktieposter, IKKE bekræftet koncern-ejerskab. Backend leverer dette fra
 * et dedikeret endpoint (Option B), så relations aldrig blandes i ejendom/gæld.
 */
class CompanyRelations extends MetisSection
{
    public array $outgoing = [];
    public array $incoming = [];
    public int $outgoingCount = 0;
    public int $incomingCount = 0;

    protected function sectionTitle(): string
    {
        return __('Aktieposter & relationer');
    }

    public function mount(string $query): void
    {
        $this->query = $query;

        if (! preg_match('/^\d{8}$/', $query)) {
            return;
        }

        $data = rescue(fn () => app(RegistryApi::class)->fetchCompanyRelations($query), []);

        $this->outgoing = $data['outgoing'] ?? [];
        $this->incoming = $data['incoming'] ?? [];
        $this->outgoingCount = $data['outgoing_count'] ?? 0;
        $this->incomingCount = $data['incoming_count'] ?? 0;
    }

    public function render()
    {
        return view('metis::livewire.sections.company-relations');
    }
}
