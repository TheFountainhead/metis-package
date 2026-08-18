<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use Livewire\Attributes\Lazy;
use Livewire\Component;

/**
 * 🚨 `isolate: false` — ellers venter sektionen paa VIEWPORTEN.
 *
 * Maalt i browseren 11/8 paa "Nordre Frihavnsgade 24, 2100": fem sektioner
 * (Markedsanalyse, Virksomheder paa adressen, Lokalplaner, Energy Label,
 * Fredning & beskyttelse) stod paa "Henter data" i det uendelige.
 *
 *   uden scroll:  9 livewire-requests, 5 haenger
 *   efter scroll: 14 requests, 0 haenger
 *
 * Hverken data, API eller PHP fejlede — hver sektions `mount()` koerte paa
 * 0,0s naar den blev kaldt direkte. Requesten blev bare aldrig SENDT, fordi
 * komponenterne laa nederst paa siden.
 *
 * 🪤 En spinner der aldrig stopper laeses som "systemet er gaaet i staa",
 * ikke som "scroll ned". Rapporteret tre gange som en fejl.
 *
 * 🪤 Bonus: `isolate: false` samler sektionerne i ÉN request frem for én pr.
 * sektion.
 */
#[Lazy(isolate: false)]
abstract class MetisSection extends Component
{
    public string $query;
    public bool $hasError = false;
    public ?string $errorMessage = null;

    /**
     * Er sektionen stoppet af kvote-gaten?
     *
     * 🚨 Saettes af `booted()`, som Livewire kalder FOER `mount()` ved hver
     * request paa komponenten. Sektionerne laeser den i deres egen `mount()`
     * via `kvoteOpbrugt()` og henter da ingen data.
     */
    public bool $gated = false;

    abstract protected function sectionTitle(): string;

    public function placeholder(): string
    {
        $title = $this->sectionTitle();
        $loadingLabel = __('Henter data');

        // Tydelig "i gang"-tilstand: brand-farvet spinner (claret) i synlig
        // størrelse + "Henter data"-tekst + skeleton med kraftigere kontrast
        // (zinc-200 mod hvid) og pulse. Den gamle grå-på-cream var for svag
        // til at signalere aktivitet.
        return <<<HTML
            <div class="bg-white rounded-xl border border-zinc-200 p-6">
                <div class="flex items-center gap-3 mb-4">
                    <span class="text-sm font-semibold text-zinc-800">{$title}</span>
                    <span class="inline-flex items-center gap-1.5 text-xs text-warm-500">
                        <span class="size-3.5 border-2 border-warm-500/25 border-t-warm-500 rounded-full animate-spin"></span>
                        {$loadingLabel}
                    </span>
                </div>
                <div class="space-y-2.5 animate-pulse">
                    <div class="h-3.5 bg-zinc-200 rounded-full w-2/3"></div>
                    <div class="h-3.5 bg-zinc-200 rounded-full w-full"></div>
                    <div class="h-3.5 bg-zinc-200 rounded-full w-5/6"></div>
                </div>
            </div>
        HTML;
    }

    /**
     * Er kvoten opbrugt? Sektionen maa da ikke hente data.
     *
     * 🚨 REVIEW-FUND 9/8, VERIFICERET MED EN KOERENDE EXPLOIT. Foerste udkast
     * gatede kun `Lookup::mount()` og skjulte sektionerne i bladen. Men hver
     * sektion er en SELVSTAENDIG `lazy` Livewire-komponent med sin egen
     * adresse — den kan mountes direkte over Livewire-endpointet uden
     * nogensinde at roere `Lookup`:
     *
     *     session(['metis_lookup_count' => 999]);          // kvote opbrugt
     *     Livewire::test('metis-company-info', ['query' => '37792594']);
     *     -> {"name":"HEMMELIG A/S","cvr":"37792594", ...}
     *
     * Fuld selskabsdata med opbrugt kvote. Testen der paastod det modsatte
     * asserterede paa MARKUP ("renderer ingen sektioner") — sandt, og ikke
     * samme paastand. Den beviste at hoveddoeren var lukket mens vinduerne
     * stod aabne.
     *
     * 🔑 DERFOR SIDDER GATEN HER, i basen som alle 28 sektioner arver. En
     * guard pr. `mount()` ville vaere samme fejl ét niveau nede: den 29.
     * sektion ville mangle den.
     *
     * 🪤 IKKE i `RegistryApi`. Den bruges ogsaa af baggrundsjob og kommandoer,
     * som ingen session har — en gate dér ville enten blokere dem eller kraeve
     * en undtagelse, og undtagelsen ville blive den nye bypass.
     *
     * 🪤 TO FORSOEG DER IKKE VIRKEDE, begge afsloeret af en maaling:
     *
     *   `booted()` + flag   — Livewires egen kilde
     *                         (`SupportLifecycleHooks::mount()`) viser
     *                         raekkefoelgen `boot → initialize → mount →
     *                         booted`. `booted()` koerer EFTER hentningen.
     *                         Maalt: `gated=true` OG
     *                         `company={"name":"HEMMELIG A/S"}`.
     *                         **Et flag er ikke en gate.**
     *   `boot()` + skipMount — flaget saettes, men sektionen hentede stadig.
     *
     * 🔑 DEN VIRKELIGE GATE LIGGER I `RegistryApi::client()`. Alle 28
     * sektioner henter derigennem, saa dét er stedet hvor alle veje moedes —
     * samme princip som `NoIndex` paa rutegruppen frem for i provideren.
     * `$gated` her er nu KUN til visning; den beskytter intet i sig selv.
     */
    public function boot(): void
    {
        $this->gated = $this->kvoteOpbrugt();
    }

    /**
     * Fejlede opslaget? Saetter da hasError og returnerer true.
     *
     * 🚨 EN TOM-TILSTAND ER EN PAASTAND, IKKE EN VISNING. "Ingen pantebreve
     * fundet" laeses som GAELDFRIHED — i en kreditvurdering en konklusion
     * nogen handler paa. Foer 18/8 returnerede resolveAddressAnalysis() `[]`
     * for BAADE fejl og tom, saa ét 422 gav 12 falske benaegtelser paa én
     * side (adresse uden postnummer, observeret i prod).
     *
     * Brug i mount() FOER felterne udtraekkes:
     *
     *     $analysis = app(RegistryApi::class)->resolveAddressAnalysis($query);
     *     if ($this->opslagFejlede($analysis)) {
     *         return;
     *     }
     *
     * Bladen skal da rendere fejl-grenen paa `$hasError` i stedet for sin
     * "ingen data"-besked. Referencemoenster: MapPanel::loadLayers().
     */
    protected function opslagFejlede(mixed $svar): bool
    {
        if (! is_array($svar) || ! isset($svar['error'])) {
            return false;
        }

        $this->hasError = true;

        // 422 fra /v1/property/analysis betyder specifikt "adressen kan ikke
        // oploeses til én matrikel" — ikke "vi kunne ikke naa serveren".
        // Brugeren kan selv rette DEN fejl ved at tilfoeje postnummer, saa
        // beskeden skal sige det frem for et generisk "noget gik galt".
        $this->errorMessage = ($svar['status'] ?? null) === 422
            ? 'address_ambiguous'
            : 'lookup_failed';

        return true;
    }

    protected function kvoteOpbrugt(): bool
    {
        if (! config('metis.gating.enabled', true)) {
            return false;
        }

        if (session('metis_user_token') || session('metis_verified_email')) {
            return false;
        }

        return session('metis_lookup_count', 0) > config('metis.gating.free_lookups', 1);
    }
}
