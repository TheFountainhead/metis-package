# Metis: Ejer-relations-graf fase 2 — Resights-billedet + berigelses-lag

**Dato:** 2026-07-25 (v3 — revideret efter to review-runder à 3 agenter + beyond-Resights-brainstorm)
**Repos:** metis-package (builder/blade) **+ metis host-app** (`resources/js/ownership-graph.js` — dagre-render + hover-kort-JS bor dér; koordineret deploy, se Leverance)
**Bygger på:** `2026-07-24-ownership-graph-relations-design.md` (fase 1, LIVE på prod 25/7)
**Reference:** Resights-eksporten `Frederik Gregers Dannisgår d Larnæs-portefølje (5).png` (Downloads, 24/7)
**Status:** Design godkendt; review-fund runde 1+2 indarbejdet; klar til implementeringsplan (2a.1)

## Mål

Fase 1-grafen viser kun ejere OPAD; datterselskaber render'es i gammel org-chart-stil under grafen, og kanter viser enkelttal. Fase 2 leverer to PR-pakker:

**2a.1 — Resights-billedet (struktur):**
1. Datterselskaber ind i grafen — nedad, 2 niveauer initialt, dybere via udvid. Gammel DATTERSELSKABER-sektion + "Udfold struktur" fjernes.
2. Ejendoms-noder (BFE + adresse + anvendelse) hængt på ejende selskab. Altid på, capped ~6 pr. selskab, hentet async efter first paint.
3. "Udvid N relationer" / "Vis N ejendomme mere"-affordances.
4. Ejerandels-visning på kanter (interval KUN hvis data kan bære det — se Interval-afsnit).

**2a.2 — Berigelses-laget (bedre end Resights):**
5. **Hover-/tap-kort** pr. node (ét singleton-kort, se Interaktionsmodel): ejendom = streetview-thumb + "Se skråfoto ↗"-link + AVM-vurdering + seneste handelspris; selskab = egenkapital/resultat/ansatte (m. regnskabsår) + website-link; person = navn + link til person-opslag (ingen berigelse — person-data hører til 2b). Alle kort linker til det fulde Metis-opslag.
6. **Værdi-aggregat på selskabs-noder** — afledt af koncern-portfolio-kaldet (gruppér `properties[]` på `owner_cvr`, summér `valuation`): "6 ejendomme · 10,4 mio. kr. (5 vurderet)". Aggregat = selskabets EGET direkte ejerskab (matcher nodens ejendomme + "Vis N mere"); dækningsgrad vises altid, da vurdering kun dækker en delmængde.
7. **Signal-markeringer** (beregnes i builderen, sendes som booleans): *negativ egenkapital* (m. regnskabsår i kortet) og *nystiftet <12 mdr.* (stiftelsesdato fra company-info — IKKE struktur-payloadens `start_date`, som er ROLLENS dato). **"Ingen regnskabsdata" er en eksplicit tredje tilstand** i hover-kortet — manglende data må aldrig ligne "sundt". (Høj-LTV-signal udskydes til fase 3.)

**Info-hierarki (bærende designprincip):** node = navn + ét nøgletal → hover/tap-kort = billede + 3-4 nøgletal + link → klik = fuldt Metis-opslag. Grafen forbliver let; dybden er ét klik væk.

**Ikke i fase 2** (se Opfølgere/Non-goals): aktieposter+gæld (fase 3), person-opslag (2b), ejendoms-opslag (2c), tidsrejse, ægte skråfoto-thumbs (STAC).

## Beslutninger

| Beslutning | Valg |
|---|---|
| Scope | 2a (selskabs-opslag) i to PR'er: 2a.1 struktur, 2a.2 berigelse. 2b/2c skåret ud som opfølgere |
| Ejendomme | Altid på (ingen toggle), cap ~6 pr. selskab, async efter first paint m. engangs-guard |
| Graf-størrelse | Ejere opad som i dag; datterselskaber 2 niveauer ned; ejendomme capped; samlet node-cap ~120. Trunkering sker i builderen FØR graphModel (payload bærer aldrig skjulte noder), deterministisk prioritet: ejendomme skæres først, så dybeste datter-lag, aldrig ejer-kæden |
| Centrum | Søgt selskab strukturelt i midten (hierarkisk dagre). Auto-center KUN ved initial render; merge/udvid bevarer zoom/pan |
| Arkitektur | Server-bygget grafmodel via `OwnershipGraphBuilder` — ren, deklarativ funktion (se nedenfor) |
| Builder-API | ÉN indgang (selskab) i 2a; multi-indgang designes ved 2b. `enrichmentData`-parameter RESERVERES i 2a.1 (default `[]`), så 2a.2 ikke ændrer signaturen |
| **Selskabs-berigelse (datavej)** | **Pooled async metis-side:** `Http::pool` (~6 samtidige) `fetchCompanyInfo` pr. selskabs-node + `Cache::remember` 24 t pr. CVR, hentet EFTER first paint, merget via builder-rebuild. ALDRIG serielt, ALDRIG før first paint. Registry-api urørt. (~1-1,5 s for 15 selskaber; 0 s ved varm cache.) Branche kan følge med her gratis |
| **Ejendoms-kort-billede** | **Streetview-thumb** (URL leveres af `properties/batch`) + "Se skråfoto ↗"-link (eksisterende viewer-URL). Ægte skråfoto-thumb (STAC + token) = parkeret opfølger |

## Arkitektur

### `OwnershipGraphBuilder` — deklarativ, ren funktion

`graphModel` genbygges i dag totalt af `pollForUpdates()`; rå appends ville blive slettet. Derfor:

```
build(structureData, propertyData, enrichmentData, expandedNodeIds, caps, now): {nodes, edges}
```

- **Alle stier** genbygger gennem builderen — mount, enrichment-poll, ejendoms-fetch-complete, berigelses-fetch-complete (2a.2), udvid-klik. Ingen sti muterer modellen direkte.
- Udvid = `expandedNodeIds`-set + rebuild → idempotent (dobbeltklik = no-op). "Vis N ejendomme mere" bruger samme mekanik (node-id i settet hæver den nodes ejendoms-cap).
- Deterministisk: samme input → samme output. Derfor er `now` en parameter (nystiftet-signalet er tidsafhængigt), og node-id'er er stabile på tværs af rebuilds.
- Ren transformation — ingen HTTP. Signal-reglerne evalueres HER (PHP, testbart); JS modtager kun `signals: ['negative_equity', …]`.
- Fase 1's `seen`/`edgeSeen`-dedup, orphan-stub-pass og `ownedTargetId`-normalisering flytter med og anvendes globalt. Centrum-id er parameter.
- Placering: `src/Services/`. Bevares i komponenten: `enriching`/poll, `$graphModel` som public property, historical-owners-visningen (læser `$owners`).

### State & cache (revideret efter runde 2)

- Rå `$ancestors`/`$subsidiaries` ophører som public Livewire-state — komponenten holder builder-input-nøgler (query, expandedIds, loaded-flags) + `graphModel`.
- `fetchCompanyStructure` caches i RegistryApi — **men KUN når enrichment-status er `complete`** (naiv cache fryser poll'ens datter-vækst; genbrug portfolio-cachens "cache ikke 'building'"-mønster).
- `fetchCompanyInfo` caches (24 t pr. CVR — er ucachet i dag). **Mount's serielle fallback-loop (pr.-ejer `fetchCompanyInfo`) fixes i 2a.1**: cache + pool eller flyt post-first-paint. Fallback-stiens data føder kun historical-visningen, ikke grafen — skal bekræftes i plan; indgår ellers som builder-input.
- Payload-hygiejne: tal som tal (formatering i Blade/Alpine), null-felter udelades. Estimat: 2a.1 ~10-15 KB; 2a.2 fuldt beriget ved 120 noder ~45-60 KB.

### Datakilder (alle eksisterende endpoints — verificeret i runde 2)

| Lag | Kilde | Status |
|---|---|---|
| Ejere opad | `fetchCompanyStructure` (ancestors) | ✅ fase 1 |
| Datterselskaber | samme kald — `getSubsidiariesTree` leverer fuldt nested træ (dybde ≤5); trunkering + N-tal er ren metis-side | ✅ verificeret |
| Ejendomme + koordinater + AVM-vurdering | `company/{cvr}/property-portfolio` — `owner_cvr`, `valuation`, `matrikel_id`, lat/lng pr. ejendom | ✅ verificeret i koncern-stien; ⚠️ felt-sæt afhænger af kodesti (se verifikation 2) |
| Handelspris + streetview-URL | `POST properties/batch` (max 200) — eager-loader `latestTransaction`+`latestValuation`+streetview | ✅ endpoint verificeret; ⚠️ verificér payload for metis-token |
| Selskabs-nøgletal + website + stiftelsesdato + branche | `fetchCompanyInfo` pr. CVR — pooled+cachet async (se Beslutninger). Website-feltet ✅ verificeret (`hjemmeside`, `gyldigTil===null`-filter) | ✅ felter findes; datavej besluttet |
| ~~AVM via fetchValuation~~ | UDGÅR — AVM ligger allerede i portfolio-payloaden; evt. detalje via `POST avm/portfolio` (kræver `avm`-token-ability, verificér) | rettet (var N+1) |

### Lazy-flow

- **First paint:** struktur-lagene. Derefter to async-trin, hver med engangs-guard og builder-rebuild ved completion: (1) ejendomme (ét koncern-kald; 'building' → backoff-poll, derefter "ejendomme hentes stadig"-note), (2) berigelse (2a.2: properties/batch + pooled company-info).
- **Poll-hensyn:** berigelses-merge udskydes til `enriching === false` — `graphModel` forbliver slank (~10-15 KB) mens 3 s-pollen kører. Poll-interval får backoff (3→5→10 s) på store koncerner.
- **Udvid:** Livewire-action → `expandedNodeIds` → rebuild. Loading-state på knappen; klik-vs-pan skelnes (mousedown-flytte-tærskel); fejl → knap tilbage til normal + markering, klik = retry (retry nulstiller relevante guards). Ingen kollaps i 2a (noteret polish).
- **Ejendom ejet af selskab uden for viste niveauer:** vises ikke før selskabet udvides ind.

### Interaktionsmodel for hover-/tap-kortet (nyt afsnit — review-krav)

- **ÉT singleton-kort** (Alpine `x-if`), positioneret ved den aktive node — IKKE et embedded kort pr. node (ville tredoble DOM og forhindre lazy billed-load). Kortet lever **uden for** `.mgraph-canvas` (skalerer ikke med zoom); position = node-pos × scale + translate, clamped mod frame-kanter.
- **Desktop:** hover åbner kortet (kort delay mod flimmer); **grace-area** mellem node og kort så musen kan nå linkene; klik på selve noden navigerer til fuldt opslag (ny adfærd — fase 1-noder er ikke klikbare i dag).
- **Mobil:** første tap på node = åbn kort; navigation KUN via link i kortet; tap udenfor lukker; ét kort ad gangen. **Simpel touch-pan (touchmove ≈ eksisterende mouse-pan) skal med i 2a.2** — uden den er indzoomede grafer utilgængelige på touch (zoom-knapperne alene flytter ikke viewporten).
- **Billeder:** loades først når kortet åbnes (singleton ⇒ 1 request pr. hover, browser-cachet). `<img>`-onerror → udelad billedet, behold kortet.
- A11y: signal-ikoner får `title`/aria-label; hover-kort er ikke keyboard-tilgængelige i 2a (kendt gap, noteret).

### Fjernes (2a.1)

- DATTERSELSKABER-sektionen + `toggleOwnerExpansion`/`expandedOwners` (~55 linjer; ingen andre consumers — verificeret).
- **Bevidst trade-off:** "Udfold struktur" på person-ejere (personens øvrige selskaber) forsvinder fra selskabssiden indtil 2b. Accepteret.
- Graf-markup + `.mgraph`-CSS udtrækkes til delt partial. `wgs84ToUtm32` udtrækkes fra `AddressSkraafoto` til `src/Services/` (fx `Utm32Projection`); sektionen delegerer.

## Node-, kant- & interval-design (frankston-stil)

- **Person-node:** mørk (#2b2333) — uændret.
- **Selskab-node:** sand-kort, Spectral-navn, mono-række `CVR-NUMMER`. 2a.2: værdi-aggregat-række + signal-ikoner + (branche via berigelsen).
- **Ejendom-node (ny):** stiplet kant, adresse-titel i ochre (#8a6d1f), mono-rækker `BFE` / `ANVENDELSE` (via `BbrUsageCategory`). Manglende felt → række udelades; manglende adresse → titel "BFE {nr}". **Node-id: `bfe:{nr}`**.
- **Kanter:** tynd rule-linje, label midt på; ejerskabs-label-reglen gælder alle ejerskabs-kanter inkl. datter-kanter. Ejendoms-kanter: ingen label.
- **JS (host-app):** `layout()` får per-node-type dimensioner; singleton-kort + koordinat-transform + touch-pan er host-app-leverancer (står i Leverance).

### Ejerandels-label — AFGJORT 26/7: eksakt tal, interval-visning DROPPET endeligt

Verifikation udført 26/7 mod (a) rå CVR-ES (deltagerRelation for 38653806 fra prod-serveren — attribut-typerne er KUN `FUNKTION`, `EJERANDEL_MEDDELELSE_DATO`, `EJERANDEL_PROCENT`, `EJERANDEL_STEMMERET_PROCENT`; INGEN interval-attribut) og (b) prod-DB-fordelingen (242.893 rækker: 51.394 × præcis 50%, plus vilkårlige præcise værdier som 51/49/45/37,5/25,5% der aldrig ville være bånd-grænser).

Konklusion: datagrundlaget skelner IKKE interval fra eksakt, og værdierne ER præcise andele. En bånd-mapning ville fejlvise titusindvis af ægte 50/50-selskaber som "50-66,7%". Resights' intervaller er deres egen visnings-heuristik uden støtte i disse data. **2a.1's eksakte visning er endelig; plan Task 12 lukket uden kodeændring.**

## Fejltilstande

- **null ≠ tom:** fejlet portfolio-/berigelses-kald må aldrig ligne "ingen data". Fejl → diskret note + retry; verificeret tom → intet lag, ingen note.
- **Signaler:** "ingen regnskabsdata" er en eksplicit tilstand (i kortet), aldrig et fraværende ikon alene.
- **Udvid-fejl:** retry-bar, idempotent. **Billed-fejl:** udelad billede, behold kort.
- **Enrichment kører:** eksisterende spinner bevares; datter-lag vokser via poll-rebuild.

## Leverance-rækkefølge

1. **2a.1 — struktur:** builder (m. reserveret enrichment-parameter), datterselskaber, ejendomme async, udvid, label-regel (efter verifikation), gammel sektion fjernes, partial-udtræk, mount-fallback-fix, JS-dimensioner. **To repos, koordineret deploy; rollback = revert begge.** `view:clear` efter Blade-ændringer.
2. **2a.2 — berigelse:** singleton hover-/tap-kort + koordinat-transform + touch-pan (host-JS), properties/batch + pooled company-info (package), værdi-aggregat, signal-ikoner, website-link, streetview-thumb + skråfoto-link, Utm32Projection-udtræk.

### Opfølgere (kræver egen mini-spec)

- **2b person-opslag:** navne- vs CPR-side (`PersonNetwork` i gammel stil skal afløses), disambiguering, first paint-strategi (N tunge kald), rollevalg, cap, person-centrum-id.
- **2c ejendoms-opslag:** adresse→BFE-resolve, ejere uden CVR-kæde, én-node-grafer skjules.
- **Fase 3:** aktieposter + gæld (og LTV-signalet). **Parkeret:** tidsrejse; ægte skråfoto-thumbs (STAC + token); hover-kort-keyboard-a11y.

## Performance (revideret efter runde 2)

- Kald-budget (15 selskaber / 30 ejendomme): mount 2 kald → post-paint 1 portfolio + 1 properties/batch + pooled company-info ≈ **19-20 kald** (naivt: 75+). Berigelse færdig ~1,5-4 s efter first paint; 0 s ved varm cache.
- dagre klarer 120 noder (~10-60 ms); DOM holdes nede af singleton-kortet. **2a.1-verifikation måler ved 120 berigede noder** (ikke kun >40), inkl. re-layout under pan.
- Payload: se State & cache. Poll-backoff + udskudt berigelses-merge holder polling-trafikken nede (ellers ~2 MB/min på store koncerner).

## Test & verifikation

- **Builder-unit-tests (rene array-tests):** lag-sammensætning, caps + deterministisk trunkering, expandedIds-idempotens, label-regel, signal-regler (m. `now`), `bfe:`-dedup, orphan-stubs, aggregat-afledning m. dækningsgrad.
- **Plan-fasens data-verifikationer (FØR kode):**
  1. Interval vs. eksakt-skelnen i CVR-data (+ float-repræsentation).
  2. Portfolio-payload **pr. kodesti** (koncern- vs EJF-sti + hvilket flag prod rammer): `owner_cvr`, `valuation`, `matrikel_id`, lat/lng, `latest_sale`, `building_usage`.
  3. `properties/batch`-payload for metis-tokenet: streetview-URL, `latestTransaction`, `latestValuation`.
  4. `avm/portfolio`: har metis-tokenet `avm`-ability, og hvad er latens ved 30 ejendomme? (>1-2 s → AVM merges i separat rebuild.)
  5. `fetchCompanyInfo`-payload: stiftelsesdato + financials + `hjemmeside` (felter verificeret i kode — bekræft i prod-svar).
  6. dagre + Livewire-morph-måling ved 120 berigede noder.
- **Browser-verifikation på ÆGTE prod-side med konsollen åben**; `view:clear` efter Blade-ændring.
- Rollback: 2a.1 = revert package-PR + host-PR sammen; registry-api urørt.

## Non-goals

- Ændringer i registry-api (pooled+cachet metis-side er valgt netop for at undgå det; mangler et felt → featuren udskydes).
- Radial layout; redigering/eksport; embedded website-previews; ægte skråfoto-thumbs (STAC); fuld touch-gestus-navigation ud over simpel pan; keyboard-tilgængelige hover-kort (noteret gap).
