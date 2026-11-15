<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

/**
 * "Stiftere og stiftede selskaber": shareholder-relationer (begge retninger).
 *
 * 🔑 registry-api skriver rollen shareholder KUN fra CVR's organisation "Stiftere"
 * (CvrService::mapRoleType), saa listen viser stiftelse, ikke ejerskab. Den hed
 * "Aktieposter & relationer" med "Aktionær i"/"Aktionærer" (rettet 15/11-2026,
 * brugervejledningen til ERST). ADSKILT fra CompanyStructure (ejere = legal_owner). Backend leverer dette fra
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
        return __('Stiftere og stiftede selskaber');
    }

    public function mount(string $query): void
    {
        if ($this->opslagAfvist($query)) {
            return;
        }

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
