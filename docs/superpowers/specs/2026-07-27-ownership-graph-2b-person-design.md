# Ejer-relations-graf fase 2b: person-siden — design

**Dato:** 2026-07-27 · **Status:** godkendt af Frederik (design-interview 27/7) · **Bygger på:** `2026-07-25-ownership-graph-phase2-design.md` (2a.1+2a.2, begge LIVE)

## Mål

Erstat `PersonNetwork`-sektionen ("Selskabsstruktur") på CPR-person-siden (`/lookup/cpr/{cpr}`) med graf-motoren fra 2a: personen som rod, ejerskabskæder nedad til datterselskaber og ejendomme, plus et **rolle-lag** (bestyrelses-/direktørposter uden ejerskab) som stiplede kanter. Den gamle org-chart-render og den separate bestyrelses-tabel bortfalder — rollerne er nu et lag i grafen.

## Beslutninger fra design-interviewet (bindende)

| Spørgsmål | Beslutning |
|---|---|
| Rolle-poster uden ejerskab | **To filter-chips "✓ Ejerskab" / "✓ Roller", BEGGE prævalgt.** Fravalg af en chip skjuler det lag; den sidste aktive chip kan ikke fravælges (UI: disabled, aldrig tom graf) |
| First paint ved mange selskaber | **Progressiv i faser** (2a's fase-model genbrugt): skelet straks fra ét hurtigt kald, derefter strukturer → ejendomme → berigelse via poll |
| Hvor vises grafen | **Kun CPR-siden.** Navne-siden (`/lookup/person/{navn}`) forbliver uændret disambiguering |

## Datagrundlag (verificeret i koden 27/7)

- `RegistryApi::fetchCompaniesByCpr($cpr)` → `companies[]` med `cvr`, `name`, `company_type`, `is_active`, `has_direct_ownership`, `roles[]` (`is_current`, `title`, `role`, `start_date`, `ownership_share`). Ét kald, hurtigt — dette er first-paint-datagrundlaget.
- `RegistryApi::fetchCrossOwnership($cvrs)` → `relationships[]` (`parent_cvr`, `child_cvr`, `ownership_share`) — bruges til dedup: selskaber i personens sæt som ejes af andre selskaber i sættet er BØRN, ikke rødder (genbrug af PersonNetworks `childCvrs`-logik).
- `RegistryApi::fetchCompanyStructureCached($cvr)` + `properties`-kaldene + `fetchCompanyInfosPooled` — uændret fra 2a.

## Grafmodel

- **Person-rod:** node-id `person:root` (én person pr. side; **CPR må ALDRIG indgå i node-id/DOM**). Kind `person` (mørk node, 2a-styling uændret).
- **Ejerskabs-lag** (chip "Ejerskab"): kant person→selskab med % fra den aktuelle rolle med `ownership_share` (som PersonNetwork i dag: første current role med share). Under hvert rod-selskab: subsidiaries/ejendomme præcis som 2a (samme builder-stier, caps, auto-udvid ≤3-kæder, capped-flags, aggregat-rækker, signaler, hover-kort).
- **Rolle-lag** (chip "Roller"): selskaber hvor personen KUN har ledelses-/bestyrelsesroller (`is_active && !has_direct_ownership`) hænger direkte på person-roden med **stiplet kant** og rolle-label (`title` ?? `role`, fx "bestyrelse", "direktør") i stedet for %-label. Rolle-selskaber er almindelige selskabs-noder: ENRICHABLE, hover-kort, klik-navigation, udvid-knapper — ingen særbehandling ud over kant-stilen.
- **Dedup på tværs af lag:** har personen både ejerskab og rolle i samme selskab, vises selskabet i ejerskabs-laget (én node); rolle-kanten tilføjes IKKE oveni (undgår dobbeltkanter person→selskab).
- Kun `is_active`-selskaber (som i dag). Historiske roller er uden for scope.

## Builder-udvidelse (multi-indgang)

`OwnershipGraphBuilder` får en person-rod-indgang — 2a-specens reserverede "multi-indgang designes ved 2b":

```php
buildForPerson(
    string $personName,
    array $ownershipCompanies,   // [{cvr, name, company_type, ownership_share}] — rødder efter cross-ownership-dedup
    array $roleCompanies,        // [{cvr, name, company_type, role_label}]
    array $structures,           // cvr => structure (kun de hentede — progressiv)
    array $properties,           // cvr => portfolio (kun de hentede)
    array $enrichment,
    array $expandedNodeIds,
    array $layers,               // ['ownership','roles'] — chips er rebuild-input
    array $caps,
    ?CarbonImmutable $now = null
): array
```

- Ren, deklarativ funktion som `build()` — ALLE stier rebuild'er; chips/faser/udvid muterer aldrig direkte.
- Genbruger de eksisterende private stier (`addSubsidiaries`, `addProperties`, `truncateToCap`, `applyEnrichment`, `aggregateProperties`, `companySignals`, auto-udvid). `addAncestors` bruges IKKE (personen ER toppen).
- Kant-shape udvides med `style: 'solid'|'dashed'` og `label` (%-tekst eller rolle-tekst). Eksisterende `build()` sætter `solid` + %-label — bagudkompatibelt.
- Caps uændret (subsidiary_depth 2, properties_per_company 6, total_nodes 120). `total_nodes`-cappen håndterer ekstreme personer; capped-flags viser "N mere"-knapper.

## Livewire-sektion: `PersonStructure` (ny, afløser `PersonNetwork`)

Registreres som `metis-person-structure`; `lookup.blade.php`s `cpr`-gren udskifter `metis-person-network`. `PersonNetwork.php` + `person-network.blade.php` slettes i samme PR (ingen dobbelt-render).

**Faser (statusmodel som 2a — `pending → loading → loaded/empty/failed` pr. fase):**
1. **Skelet (mount):** `fetchCompaniesByCpr` → split ejerskab/rolle (PersonNetworks eksisterende klassifikation) → `fetchCrossOwnership` (ét kald, kun ved ≥2 ejerskabs-cvr'er) → rebuild. Grafen viser person + alle selskabs-noder med det samme.
2. **Strukturer (poll):** pr. poll-tick hentes strukturer for op til **3** endnu-uhentede rod-selskaber (`fetchCompanyStructureCached`), i den stabile rækkefølge fra `fetchCompaniesByCpr` → rebuild pr. tick — grafen vokser synligt. Fejl pr. cvr noteres; én samlet retry-knap for fasen.
3. **Ejendomme (poll, efter strukturer):** som 2a's `loadProperties` — pr. selskab i grafen; building-backoff og limits genbruges.
4. **Berigelse (trailing):** `loadEnrichment`-mønsteret uændret (gated på settled properties; pooled concurrency 6; financials-enheden er KILDE-afhængig — pdf⇒t.DKK×1000, API⇒kroner passthrough, jf. #113).
- `rehydrateBeforeRebuild`-guards som 2a (alle datasæt + `layers` + `expandedNodeIds` overlever request-grænser).
- Chips (`$layers`) er Livewire-state; toggle → rebuild. **Aldrig-tom-regel (præciserer "sidste chip"):** en chip kan ikke fravælges, hvis fravalget ville efterlade nul synlige noder ud over personen — en chip for et TOMT lag kan derimod altid fravælges. Server-side håndhævet (frontend disabler blot knappen).
- Tom-tilstande: ingen aktive selskaber overhovedet → "Ingen aktive selskabsrelationer" (sektionen viser ikke tom graf). Kun rolle-selskaber → grafen viser person + rolle-lag; Roller-chippen kan ikke fravælges (aldrig-tom-reglen), Ejerskab-chippen kan.

## Host-JS (lille PR)

- Kant-rendering læser `style`/`label` fra edge-shapen: `dashed` → `stroke-dasharray`, label-tekst uden %-antagelse. NODE_DIMS, pan/zoom, kort-handlers, auto-refit: uændret.
- Chips er Blade/Livewire — ingen JS-state (wire:click + disabled-attribut).

## Tests

- **Builder (Pest):** person-rod + ejerskabskanter med %; rolle-kanter dashed med label; lag-filtrering (kun ownership / kun roles / begge); dedup ejerskab-vinder-over-rolle; cross-ownership-barn er ikke rod; progressive strukturer (delmængde af structures → delgraf, deterministisk); caps + auto-udvid genbrugt på person-rod; CPR aldrig i node-id (fixture med CPR-agtig query asserterer fravær).
- **Livewire (Pest):** fase-sekvens skelet→strukturer(3 pr. tick)→ejendomme→berigelse; retry pr. fase; chips-toggle rebuild'er; sidste-chip-reglen; rehydrate over to requests; tom-tilstande. `Http::fake`-fixtures for `companies-by-cpr` + `cross-ownership` tilføjes ved siden af 2a's.
- Fuld suite grøn før PR.

## Leverance & deploy

Package-PR (builder + PersonStructure + blades + tests) + host-PR (kant-style/label i graf-JS). Samme sekvens som 2a.2: package-merge → lock-bump (auto-deploy) → host-merge → `view:clear` → prod-verifikation med konsol åben på en CPR-side (kendt test-case: Lars Sørensen via Lars Horsbøl-flowet) + Flare-watch.

## Non-goals

Navne-siden (uændret), historiske roller, tidsrejse, aktieposter+gæld (fase 3), ejendoms-siden (2c), ægte skråfoto-thumbs (STAC).
