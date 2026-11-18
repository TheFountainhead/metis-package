<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use TheFountainhead\Metis\Services\RegistryApi;

/**
 * "Koncern": hvilket selskab der står i toppen, og hvilke selskaber der hænger sammen med dette via
 * dansk selskabsejerskab. Svarer på "hvad er samme ejer ellers forbundet til" for en låntager.
 *
 * Sektionen vises ikke, når selskabet står alene (ingen koncern), eller når opslaget fejler: den er et
 * supplement, og en tom boks ville være støj. Personer indgår ikke (koncernen følger kun selskaber).
 */
class CompanyKoncern extends MetisSection
{
    /** @var array<string, mixed>|null */
    public ?array $koncern = null;

    protected function sectionTitle(): string
    {
        return __('Koncern');
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

        $data = rescue(fn () => app(RegistryApi::class)->fetchCompanyKoncern($query), null, false);

        if (is_array($data) && (int) ($data['selskaber_i_alt'] ?? 1) > 1) {
            $this->koncern = $data;
        }
    }

    public function render()
    {
        return view('metis::livewire.sections.company-koncern');
    }
}
