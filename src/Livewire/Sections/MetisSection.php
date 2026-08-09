<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use Livewire\Attributes\Lazy;
use Livewire\Component;

#[Lazy]
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
