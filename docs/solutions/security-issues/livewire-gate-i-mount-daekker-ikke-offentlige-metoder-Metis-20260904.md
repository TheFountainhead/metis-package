---
module: Metis
date: 2026-09-04
problem_type: security_issue
component: livewire
symptoms:
  - "En gate i mount() dækkede kun første render; load()/search()/downloadCsv() var offentlige Livewire-metoder og kunne kaldes uden token"
  - "Testen 'API-et kaldes ikke uden token' var grøn af den forkerte grund: den kørte kun mount()"
  - "Middleware registreret på provideren var ikke dækket af tests: Testbench indlæser routes-filen direkte uden provider-gruppen"
root_cause: logic_error
laravel_version: 12.55.1
resolution_type: code_fix
severity: high
tags: [livewire, gate, public-methods, mount, testbench, middleware, cookies, pilot-token, 50c]
---

# Troubleshooting: en gate i mount() dækker ikke Livewires offentlige metoder

## Problem

Cockpittet (`/engagementer`, PR #182) skulle kun vise data for pilotbrugere med token i sessionen. Gaten sad i `mount()`. Men enhver `public function` på en Livewire-komponent kan kaldes af klienten (`wire:click`, `$wire.call`), og `load()` kaldte API'et på den delte tenant-nøgle uden at spørge om token. Reviewet af #182 fandt det før merge; testen for tilstanden uden token var grøn, fordi den kun gik gennem `mount()`. `/soeg` (PR #183) blev bygget med mønstret fra start.

Samme fejlklasse som `NoIndex`-kommentaren i `routes/web.php` beskriver (9/8): beskyttelsen dækker den vej, man selv gik, ikke de andre veje ind.

## Løsning

1. Gaten sidder ved hver metode der kalder API'et (ikke i mount/updated, som kun når API'et gennem search()). For `/soeg` sidder den igen i API-klienten: `RegistryApi::debtSearch()` og `createDebtSearchCsvLink()` afviser selv uden token (403 `pilot_required`), så en fremtidig kalder ikke omgår komponenten. Cockpittets `fetchEngagements()`/`fetchEngagement()` har IKKE et klient-tjek; her hviler afgrænsningen på komponentgaten og på registry-api, der binder svaret til brugerens eget CVR.
2. Prædikatet bor ét sted: trait `HasPilotToken` (delt af /soeg og cockpittet; `gating.enabled=false` i embedded mode kræver intet token).
3. Test der kalder metoderne direkte uden token og derefter `Http::assertNothingSent()`: `tests/Feature/DebtSearchTest.php` ("uden pilot-token") og `tests/Feature/EngagementsTest.php` ("load() uden token"). Mutation (gaten i search() fjernet) gør testen rød.
4. `#[On('email-verified')]`-lytter, så en pilot der bekræfter sin mail på siden ikke står med muren til næste genindlæsning.

## Tre fælder i testene fundet undervejs

- Package-ruter indlæses i tests direkte fra `routes/web.php` UDEN provider-gruppen. Middleware skal derfor stå i routes-filens egen gruppe (sammen med `NoIndex`), ellers er den ikke dækket af tests og kan drifte.
- Uden `web`-gruppen kører `EncryptCookies` ikke i tests: brug `withUnencryptedCookie()`; `withCookie()` giver en krypteret værdi som middlewaren ikke kan læse.
- Laravels `Http::fake()` LÆGGER TIL: to fakes af samme mønster i én test giver den første. Én tilstand pr. test.

## Forebyggelse

Spørg ved hver gated Livewire-komponent: hvilke `public function`s findes, og kan hver af dem nå API'et uden at passere gaten? Testen skal kalde dem, ikke kun mounte.
