# Ejer-relations-graf fase 2b: person-siden — design

**Dato:** 2026-07-27 · **Version:** 2 (multi-agent spec-review indarbejdet: 4 agenter — flow, arkitektur, performance, kode-grounding) · **Status:** afventer Frederiks godkendelse · **Bygger på:** `2026-07-25-ownership-graph-phase2-design.md` (2a.1+2a.2, begge LIVE)

## Mål

Erstat `PersonNetwork`-sektionen ("Selskabsstruktur") på CPR-person-siden (`/lookup/cpr/{cpr}`) med graf-motoren fra 2a: personen som rod, ejerskabskæder nedad til datterselskaber og ejendomme, plus et **rolle-lag** (bestyrelses-/direktørposter uden ejerskab) som stiplede kanter. Den gamle org-chart-render og den separate bestyrelses-tabel bortfalder — rollerne er nu et lag i grafen.

## Beslutninger fra design-interviewet (bindende)

| Spørgsmål | Beslutning |
|---|---|
| Rolle-poster uden ejerskab | **To filter-chips "✓ Ejerskab (N)" / "✓ Roller (M)" med tælle-badges, BEGGE prævalgt.** Fravalg af en chip skjuler det lag. **Aldrig-tom-regel:** en chip kan ikke fravælges hvis fravalget ville efterlade nul synlige noder ud over personen; en chip for et TOMT lag kan altid fravælges. Server-side håndhævet, frontend disabler blot knappen |
| First paint ved mange selskaber | **Progressiv i faser** (2a's fase-model genbrugt): skelet straks fra ét hurtigt kald, derefter strukturer → ejendomme → berigelse via poll |
| Hvor vises grafen | **Kun CPR-siden.** Navne-siden (`/lookup/person/{navn}`) forbliver uændret disambiguering |

## Datagrundlag (kode-grounded 27/7, felt-navne verificeret via PersonNetworks consumer-kode)

- `RegistryApi::fetchCompaniesByCpr($cpr)` (`POST /v1/cvr/search-by-cpr`, returnerer `?array`) → `companies[]` med `cvr`, `name`, `company_type`, `is_active`, `has_direct_ownership`, `roles[]` (`is_current`, `title`, `role`, `start_date`, `ownership_share`). **Ny 5-min cache keyed på en HASH af CPR** (aldrig rå CPR i cache-key) — billiggør tilbage-navigation og rehydrering.
- `RegistryApi::fetchCrossOwnership($cvrs)` → `relationships[]` (`parent_cvr`, `child_cvr`, `ownership_share`).
- `fetchCompanyStructureCached($cvr)` — **kun når registry-api's `getEnrichmentStatus` for cvr'et er `completed`** (metodens egen kontrakt: cached variant fryser ellers datter-vækst i 5 min); ellers den ucachede `fetchCompanyStructure` (= PersonNetworks nuværende adfærd).
- `fetchCompanyPropertyPortfolio` + `fetchPropertiesBatch` + `fetchCompanyInfosPooled` — uændret fra 2a.

## Grafmodel

- **Person-rod:** node-id `person:root` (én person pr. side; kolliderer ikke med builderens `person:<md5>`-ejere). **CPR må ALDRIG indgå i node-id, kanter, DOM-attributter eller cache-keys.** Scope-præcisering: Livewire-komponentens `$query` ER CPR'et (som i dag — URL'en bærer det allerede); reglen gælder graf-payloaden. Person-roden er IKKE klikbar og har intet selv-link i hover-kortet (den ER siden).
- **Node-kind:** ejerskabs-rødder OG rolle-selskaber får `kind: 'subsidiary'` med `depth: 1`. Dermed virker `ENRICHABLE_KINDS`-gaten, `truncateToCap` og host-JS' `COMPANY_KINDS`-klik-navigation uændret — ingen ny kind, ingen udvidelse af de fire kind-consumers.
- **Ejerskabs-lag** (chip "Ejerskab"): kant person→selskab med % fra første current rolle med `ownership_share` (PersonNetworks regel; mangler share → kant uden label). Under hvert rod-selskab: subsidiaries/ejendomme som 2a (samme builder-stier, caps, auto-udvid ≤3-kæder, capped-flags, aggregat-rækker, signaler, hover-kort).
- **Rolle-lag** (chip "Roller"): selskaber med `is_active && !has_direct_ownership` hænger på person-roden med **stiplet kant** og rolle-label (`title` ?? `role` ?? `'rolle'`). Rolle-selskaber er almindelige selskabs-noder: beriges, hover-kort, klik-navigation, udvid-knapper.
- **Lag-bevidst dedup (dobbelt-relation):** har personen både ejerskab og rolle i samme selskab, vises selskabet med BEGGE lag aktive kun i ejerskabs-laget (én node, ingen ekstra rolle-kant). Er ejerskabs-laget FRAVALGT, renderes selskabet i rolle-laget med stiplet kant — en bestyrelsespost må aldrig forsvinde fra rolle-visningen, blot fordi personen også ejer.
- **Rolle-selskab der også er subsidiary i et ejerskabs-træ** (fx person er direktør i Drift B, som ejes af personens Holding A): node-dedup via `cvr:`-id (én node, to forældre — builderens multi-parent-mønster fra 2a), og BEGGE kanter beholdes (rolle-kanten er et selvstændigt faktum).
- **Cross-ownership:** `relationships` er builder-input. Selskaber i personens sæt ejet af andre selskaber i sættet er BØRN, ikke rødder — og parent→child-kanten tegnes allerede i SKELETTET fra relationships (barnet må ikke være forældreløst mens fase 2 henter strukturer). PersonNetworks injektion-fallback bevares som builder-regel: en relationship-kant vinder over fravær i forælderens struktur-payload. Ejer personen desuden barnet DIREKTE, tegnes person→barn-kanten også (begge kanter er sande; legacy-adfærden der droppede den direkte kant videreføres IKKE).
- Kun `is_active`-selskaber (som i dag). Historiske roller uden for scope.
- **First-level caps + truncation:** skelettet viser højst **20 ejerskabs-rødder** og **15 rolle-selskaber** (deterministisk: `fetchCompaniesByCpr`-rækkefølgen); resten bag udvid-knapper med CPR-frie ids `sub:person:root` / `roles:person:root` (capped-flag-mønsteret fra 2a). Trunkerede rødder deltager IKKE i fase 2/3 før de udvides. Person-grafens `truncateToCap`-prioritet: ejendomme → dybeste datter-lag → rolle-selskaber → ejerskabs-rødder sidst.

## Builder-udvidelse (multi-indgang)

`OwnershipGraphBuilder` får en person-rod-indgang — 2a-specens reserverede "multi-indgang designes ved 2b":

```php
buildForPerson(
    string $personName,
    array $ownershipCompanies,   // [{cvr, name, company_type, ownership_share}] — rødder efter cross-ownership-dedup
    array $roleCompanies,        // [{cvr, name, company_type, role_label}]
    array $crossOwnership,       // relationships[] {parent_cvr, child_cvr, ownership_share}
    array $structures,           // cvr => structure (kun de hentede — progressiv)
    array $properties,           // cvr => portfolio (kun de hentede)
    array $enrichment,
    array $expandedNodeIds,
    array $layers,               // ['ownership','roles'] — chips er rebuild-input
    array $caps,
    ?CarbonImmutable $now = null
): array
```

- Ren, deklarativ funktion som `build()` — ALLE stier rebuild'er; chips/faser/udvid muterer aldrig direkte. `addAncestors` bruges ikke (personen ER toppen; orphan-stub-passet er ancestors-specifikt og aktiveres ikke).
- **Delt kerne, ingen copy-paste af halen:** usage-merge + `truncateToCap` + `applyEnrichment` udtrækkes til en delt protected `finalize()`-sti som både `build()` og `buildForPerson()` kalder. Person-stien fladgør properties-maps til én liste før `addProperties`/`aggregateProperties`, og `$query`-parameteren i de tre helpers der normaliserer `owner === $query → 'searched'` neutraliseres (person-grafen har intet 'searched'-alias).
- **Kant-shape:** kanter bærer allerede `label` (%-tekst via `shareLabel()`, tom for ejendomme) — labelen genbruges til rolle-tekst. KUN `style: 'solid'|'dashed'` er nyt felt; **fravær af `style` = solid** (bagudkompatibelt: eksisterende `build()` sætter intet style-felt).
- Caps: 2a-værdierne uændret (subsidiary_depth 2, properties_per_company 6, total_nodes 120) + first-level-caps ovenfor. (Builderens hjælpe-metoder er `protected`, ikke private.)

## Livewire-sektion: `PersonStructure` (ny, afløser `PersonNetwork`)

Registreres som `metis-person-structure` (navnet er frit); `lookup.blade.php`s cpr-gren udskifter `metis-person-network`. **Sletteliste (samme PR):** `PersonNetwork.php`, `person-network.blade.php` OG registreringslinjen i `MetisServiceProvider` (verificeret: ingen andre referencer findes).

**Statusmodel — 2b definerer sine EGNE fase-enums (2a's konsolideres ikke; 2a har `pending/building/loaded/empty/failed` for properties og `pending/loaded/failed` for enrichment):**
- `skeletonStatus`: `pending/loading/loaded/empty/failed`
- `structuresStatus` (fase-aggregat) + `structureByCompany` per-cvr-map: `pending/loading/loaded/failed`
- `propertiesStatus` (fase-aggregat) + `propertiesByCompany` per-cvr-map: `pending/building/loaded/empty/failed`
- `enrichmentStatus`: `pending/loaded/failed`

**Faser:**
1. **Skelet (mount):** `fetchCompaniesByCpr` → split ejerskab/rolle (PersonNetworks klassifikation) → `fetchCrossOwnership` (ét kald, kun ved ≥2 ejerskabs-cvr'er) → rebuild. **null ≠ tom:** returnerer kaldet `null`/fejler (eller fejler cross-ownership — uden dedup ville grafen være FORKERT, ikke blot ufuldstændig), er skelettet `failed` → fejlnote + retry-knap der genkører fase 1. "Ingen aktive selskabsrelationer" vises KUN ved succesfuldt tomt svar.
2. **Strukturer (poll):** `wire:poll.2s` gated på `structuresStatus === 'loading'`. Pr. tick hentes op til **3** endnu-uhentede synlige rod-selskaber (ejerskabs-rødder OG rolle-selskaber — begge skal kunne udvise datterselskaber) via `Http::pool` (concurrency 3, så tick-tid = max ikke sum), i `fetchCompaniesByCpr`-rækkefølgen → rebuild pr. tick. Fejl pr. cvr i per-cvr-mappet; én samlet retry-knap for fasen. **Retry-kaskade:** fase-2-retry resetter fase-3-status for de berørte cvr'er og `enrichmentStatus → pending` (spejler 2a's `retryProperties`-disciplin — ellers forbliver nye subtræer permanent uberigede).
3. **Ejendomme (poll):** starter når fase 2 ikke har `pending`/`loading` cvr'er (`failed` blokerer ikke). **Én `fetchCompanyPropertyPortfolio` pr. SYNLIGT rod-selskab** (dækker subtræet via `owner_cvr`-gruppering som 2a — ALDRIG pr. node), 3 pr. tick, building-backoff pr. cvr som 2a men med **delt fase-attempts-budget på 24**; opbrugt budget → fasen `failed` med én retry-knap.
4. **Berigelse (trailing):** `loadEnrichment`-mønsteret fra 2a (gated på settled properties-aggregat; pooled concurrency 6; financials-enheden er KILDE-afhængig — pdf⇒t.DKK×1000, API⇒kroner, jf. #113). **Batch-kaldet (`fetchPropertiesBatch`) sender KUN matrikel-ids for ejendomme der faktisk står i graf-modellen** (~≤200 ids, 1 chunk) — aldrig unionen af fulde 500-per-selskab-porteføljer (38 sekventielle chunks + alt-eller-intet-fejl).
- **Rehydrering:** alle datasæt + `layers` + `expandedNodeIds` er **protected state** (aldrig i wire-payload), genopbygges cache-first som 2a — men **partial-tolerant**: rebuild med det der stadig er cachet; manglende cvr'er får reset fase-status og hentes af poll-loopet (aldrig mere end én ticks batch inde i en interaktiv request som chip-toggle/udvid).
- **Chips:** Livewire-state; toggle → rebuild + host-JS refit. **Hentning fortsætter uanset chip-tilstand** (chips filtrerer kun bygningen) — genaktivering viser allerede-hentet data øjeblikkeligt.
- Tom-tilstande: ingen aktive selskaber → "Ingen aktive selskabsrelationer" (ingen tom graf). Kun rolle-selskaber → person + rolle-lag; Roller-chippen låst af aldrig-tom-reglen, Ejerskab-chippen kan fravælges.
- Tilbage-navigation remounter sektionen (chips/udvid/faser genstarter) — accepteret default, ingen persistens.
- **Payload-budget (accepteret):** graf-modellen er public state og sendes begge veje pr. request; ved 120 berigede noder ≈ 90KB serialiseret. Accepteret som i 2a; flyttes evt. til JS-event-push i senere fase hvis mobil-problem.

## Host-JS (lille PR — deployes FØRST)

- `style` skal med gennem dagre: `setEdge(..., { label, style })` og edge-svg får klasse-variant ved `dashed` (`stroke-dasharray`); CSS-varianten bor i graf-partialens styles. Labels kræver INTET JS-arbejde (renderes allerede verbatim med halo).
- Rent additivt mod den gamle builder (fravær af style = solid) → **host-JS-PR'en merges og deployes før package-PR'en**; omvendt rækkefølge ville give et vindue hvor rolle-kanter renderer solide.
- NODE_DIMS, pan/zoom, kort-handlers, auto-refit: uændret.

## Tests

- **Builder (Pest):** person-rod + ejerskabskanter med % (og kant uden label ved manglende share); rolle-kanter dashed med label + `'rolle'`-fallback; lag-filtrering (kun ownership / kun roles / begge); lag-bevidst dobbelt-relation-dedup (begge lag → én node i ejerskab; kun roles-lag → node med rolle-kant); rolle-selskab-som-subsidiary = én node, begge kanter; cross-ownership-barn er ikke rod + parent-kant i skelettet + injektion-fallback + direkte person→barn-kant; progressive strukturer (delmængde → deterministisk delgraf); first-level-caps med `sub:person:root`/`roles:person:root`-udvid; truncation-prioritet; kind='subsidiary' på rødder/rolle-selskaber (enrichment virker); `style`-fravær = solid på alle `build()`-kanter; CPR aldrig i node-id/kanter (fixture asserterer fravær).
- **Livewire (Pest):** fase-sekvens; skelet-null → failed + retry (≠ empty); strukturer 3/tick + per-cvr-fejl + retry-kaskade (fase 3 + enrichment resettes); fase 3 starter med failed-cvr'er i fase 2; delt attempts-budget; enrichment-batch kun in-graph-ids; chips-toggle rebuild + aldrig-tom-regel + fortsat hentning for skjult lag; rehydrate partial-tolerant over to requests; tom-tilstande. `Http::fake`-fixtures for `search-by-cpr` + `cross-ownership` ved siden af 2a's.
- Fuld suite grøn før PR.

## Leverance & deploy

1. Host-PR (kant-style i graf-JS) → merge + auto-deploy (risikofrit additivt).
2. Package-PR (builder + PersonStructure + blades + sletteliste + tests) → merge → lock-bump (auto-deploy) → `view:clear`.
3. Prod-verifikation med konsol åben på en CPR-side (kendt case: Lars Sørensen via Lars Horsbøl-flowet) + Flare-watch.

## Non-goals

Navne-siden (uændret), historiske roller, tidsrejse, aktieposter+gæld (fase 3), ejendoms-siden (2c), ægte skråfoto-thumbs (STAC), **selskabssidens person-ejer-udfoldning** (2a's "indtil 2b"-trade-off indfries IKKE af 2b — kræver navn→CPR-bro; åben opfølger), persistens af chips/udvid over navigation.
