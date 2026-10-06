<?php

namespace TheFountainhead\Metis\Livewire\Sections;

use Livewire\Attributes\Lazy;
use Livewire\Attributes\Locked;
use TheFountainhead\Metis\Services\LookupAccess;
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
    /**
     * 🚨 #[Locked] (fix-runde 1, review V1). Refresh-handlerne (fx
     * `CompanyTinglysning::retry()`, `CompanyStructure::loadProperties()`,
     * `CompanyProperties::loadMore()`) henter paa `$this->query` EFTER mount.
     * Maalt (review POC C): `updates: {query: "99999999"}` + `retry` hentede
     * pantebreve for et vilkaarligt CVR.
     *
     * 🪤 Sikkert her, i modsaetning til `PersonStructure::$source`
     * (PersonStructure:200): `query` forbruges af `mount(string $query)` og
     * saettes altsaa af mount, ikke som en property-tildeling ved lazy-
     * hydreringen. Bevist med rigtige `__lazyLoad`-rundture over HTTP.
     */
    #[Locked]
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

        // 🚨 FIX-RUNDE 1 (review V1): ved hver EFTERFOELGENDE request paa en
        // sektion (retry, poll, loadMore …) er `query` hydreret fra
        // snapshottet. Er opslaget ikke godkendt i DENNE session, er det et
        // genbrugt snapshot fra en anden session: stop foer metoden koerer.
        //
        // 🪤 Ved selve `__lazyLoad` er `query` endnu ikke sat (mount har ikke
        // koert), saa den vej daekkes af `opslagAfvist()` i mount.
        if (isset($this->query) && ($type = $this->opslagsType())
            && ! app(LookupAccess::class)->erGodkendt($type, $this->query)) {
            abort(403);
        }
    }

    /**
     * Hvilken opslagstype sektionen hoerer til, for godkendelseslisten.
     *
     * Adresse- og selskabssektioner. Personsektioner kraever en identificeret
     * bruger i datalaget og er dermed fritaget fra listen (null).
     */
    protected function opslagsType(): ?string
    {
        $navn = class_basename(static::class);

        return match (true) {
            str_starts_with($navn, 'Address') => 'address',
            str_starts_with($navn, 'Company') => 'cvr',
            default => null,
        };
    }

    /**
     * Foerste linje i mount for sektioner der henter paa et CVR.
     *
     * 🚨 FIX-RUNDE 1 (review V1): et `__lazyLoad`-payload er signeret med
     * APP_KEY, ikke med sessionen. Uden denne linje kunne et payload fra ét
     * legitimt opslag sendes fra en frisk session (eller en anden IP) og give
     * selskabets data igen og igen. Adressesektionerne daekkes i datalaget
     * (`RegistryApi::resolveAddressAnalysis()`), fordi de alle henter derigennem.
     */
    protected function opslagAfvist(string $query): bool
    {
        $type = $this->opslagsType();

        if ($type === null || app(LookupAccess::class)->erGodkendt($type, $query)) {
            return false;
        }

        $this->query = $query;
        $this->hasError = true;
        $this->errorMessage = 'lookup_failed';

        return true;
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
        // 🚨 `null` TAELLER SOM FEJL — og det er en LIVE produktionssti.
        //
        // Den rammer hver uautentificeret bruger der har brugt sit gratis
        // opslag: `client()` kaster `QuotaExceededException` FOER HTTP-kaldet,
        // og `post()` fanger kun `RequestException`/`ConnectionException`.
        // QuotaExceededException extends RuntimeException, saa den slipper ud
        // af post() og bliver til `null` gennem sektionernes `rescue()`.
        // Uden denne gren ville en kvote-opbrugt bruger se "0 Ejendomme" i
        // stedet for at faa at vide at opslaget ikke blev udfoert.
        //
        // 🪤 JEG KONKLUDEREDE FOERST AT GRENEN VAR DOED KODE. Min probe
        // testede ConnectionException, RuntimeException og HTTP 500 — alle
        // kastet fra HTTP-FAKEN, altsaa EFTER at client() var passeret.
        // post() fanger dem, saa de kommer aldrig frem som null. Jeg
        // konstruerede ikke den tilstand hvor det positive kunne ske; review
        // gjorde (18/8) og maalte `rescue(...) === null` ved kvote-gaten.
        //
        // Andre live null-kilder: fetchSimilarSales() (bar
        // `catch (\Throwable) { return null; }`), fetchPersonPropertyPortfolio()
        // og alt der gaar gennem get()/getEnvelope()/postEnvelope(), som er
        // deklareret `?array` og IKKE har post()'s `?? ['error' => …]`-vaern.
        if ($svar === null) {
            $this->hasError = true;
            $this->errorMessage = 'lookup_failed';

            return true;
        }

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
        // Samme regel som `RegistryApi::kvoteOpbrugt()`, fra samme sted.
        // Foer 6/10 var det en tredje kopi af taersklen.
        return app(\TheFountainhead\Metis\Services\LookupAccess::class)->anonymKvoteOverskredet();
    }
}
