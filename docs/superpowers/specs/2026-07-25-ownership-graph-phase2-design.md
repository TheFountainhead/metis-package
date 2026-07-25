# Metis: Ejer-relations-graf fase 2 — Resights-billedet (datterselskaber + ejendomme + intervaller)

**Dato:** 2026-07-25
**Repo:** metis-package (render) — registry-api genbruges uændret
**Bygger på:** `2026-07-24-ownership-graph-relations-design.md` (fase 1, LIVE på prod 25/7)
**Reference:** Resights-eksporten `Frederik Gregers Dannisgår d Larnæs-portefølje (5).png` (Downloads, 24/7) — FDL-Invest-koncernen: person → selskaber → datter-datterselskaber → 13 ejendomme, interval-labels på kanterne, "Udvid N relationer"-affordances.
**Status:** Design godkendt (sektion 1-3 godkendt enkeltvis), klar til implementeringsplan

## Mål

Fase 1-grafen viser kun ejere OPAD; datterselskaber render'es stadig i den gamle org-chart-stil under grafen (to designsprog på én side), og kanter viser enkelttal. Fase 2 leverer Resights-billedet:

1. **Datterselskaber ind i grafen** — nedad, 2 niveauer initialt, dybere via udvid. Den gamle DATTERSELSKABER-sektion og "Udfold struktur"-toggles fjernes.
2. **Ejendoms-noder** — BFE + adresse + anvendelse, hængt på ejende selskab. Altid på (ingen toggle), capped pr. selskab, hentet asynkront efter first paint.
3. **Ejerandels-intervaller** på kanter ("33,3-50%", ikke "33,33 %").
4. **"Udvid N relationer"-affordances** på noder hvor der er skåret.
5. **Grafen på alle tre opslagstyper** — selskab, person og ejendom, med den søgte entitet som strukturelt centrum.

**Ikke i fase 2** (forbliver fase 3): aktieposter- og gæld-lag.

## Beslutninger (fra brainstorm 25/7)

| Beslutning | Valg |
|---|---|
| Scope | Resights-billedet; aktieposter+gæld udskudt til fase 3 |
| Ejendomme | Altid på (ingen toggle — afviger bevidst fra fase 1-spec'en), capped ~6 pr. selskab, "Vis N ejendomme mere"-affordance |
| Graf-størrelse | Dybde-bound: ejere opad som i dag, datterselskaber 2 niveauer ned, ejendomme capped; resten bag udvid |
| Centrum | Søgt entitet står strukturelt i midten (ejere over, koncern/ejendomme under) — hierarkisk dagre-layout, IKKE radial. Viewport auto-centrerer på søgt node |
| Opslagstyper | Alle tre (selskab + person + ejendom), leveret som 2a → 2b → 2c |
| Arkitektur | **Tilgang A:** server-bygget grafmodel — én delt PHP-builder; Alpine/dagre gør kun layout+render |

## Arkitektur

### `OwnershipGraphBuilder` (ny klasse, metis-package)

Udtrækkes af `CompanyStructure::ownershipGraphData()` og producerer den `{nodes, edges}`-model fase 1-render allerede forstår. Tre indgange, samme output:

| Indgang | Centrum | Datakilder (alle eksisterende registry-api-endpoints) |
|---|---|---|
| `forCompany(cvr)` | søgt selskab | `fetchCompanyStructure` (ejere/ancestors som nu + datterselskaber nedad 2 niveauer) + `company/{cvr}/property-portfolio` (koncernens ejendomme) |
| `forPerson(name)` | personen øverst | `fetchPersonRoles` → selskaber med ejerandel → pr. selskab koncern nedad + ejendomme |
| `forProperty(bfe)` | ejendommen nederst | ejendommens ejer(e) → ejerkæden opad via samme strukturdata |

- Node-id-disciplin fra fase 1 bevares (cvr for selskaber, `person:<md5 med rækkeindex>` for personer, dedup på BFE for ejendomme).
- Edge-dedup, orphan-stub-pass og cycle-guard-data genbruges uændret.
- registry-api røres IKKE — alle lag har endpoints i forvejen.

### Lazy-flow (ejendomme + udvid)

- **First paint:** grafen render'es straks med selskabs-lagene (data der allerede hentes i dag).
- **Ejendomme:** hentes asynkront efter first paint (ét koncern-kald via KoncernPortfolioService-endpointet) og flettes ind i `graphModel` → den eksisterende `$wire.$watch('graphModel')`-mekanik re-layouter på plads uden at miste zoom/pan (samme mønster som enrichment-poll i dag).
- **Udvid:** "Udvid N relationer" (dybere datterselskaber / flere ejer-lag) og "Vis N ejendomme mere" er Livewire-actions der appender til `graphModel` → re-layout på plads. Ét server-roundtrip pr. klik er acceptabelt for et opslagsværktøj.

### Fjernes

- DATTERSELSKABER-sektionen (gammel org-chart-stil: rød tekst + pille-badges) i `company-structure.blade.php`.
- "Udfold struktur"-toggle (`toggleOwnerExpansion`/`expandedOwners`) — erstattet af udvid-affordances i grafen.

## Node-, kant- & interval-design (frankston-stil)

Referencebilledets *struktur*, frankston.io's *stil* (sand, Spectral, mono — ikke Resights' hvide tema):

- **Person-node:** mørk (#2b2333), hvid tekst — uændret fra fase 1.
- **Selskab-node:** sand-kort, Spectral-navn, mono-rækker `CVR-NUMMER` / `BRANCHE` (branche er NY — data findes i `fetchCompanyInfo`). Søgt selskab fremhævet som i dag.
- **Ejendom-node (ny):** stiplet kant, adresse som titel i ochre (#8a6d1f), mono-rækker `BFE` / `ANVENDELSE` (via `BbrUsageCategory`). Mangler BFE/anvendelse → rækken udelades.
- **Kanter:** tynd rule-linje med label midt på; datterselskabs- og ejendomskanter i samme sprog som ejer-kanter (retningen bærer semantikken).
- **Udvid-affordance:** lille mono-tekstknap i kortets bund ("↓ Udvid 2 relationer" / "Vis 4 ejendomme mere").

### Interval-mapning (erstatter `shareLabel()`)

CVR registrerer ejerandele i officielle bånd; det gemte tal (`EJERANDEL_PROCENT` × 100) er båndets nedre grænse. Regel:

- Tal == kendt bånd-nedre-grænse (5, 10, 15, 20, 25, 33,33, 50, 66,66, 90) → vis båndet, fx "33,3-50%".
- Tal == 100 → "100%".
- Alt andet (fx en reel ejers præcise 27,43%) → vis det præcise tal som i dag.

Reglen kan aldrig gøre en korrekt værdi mindre præcis (værste fald = dagens visning). Ren metis-render-ændring; ingen datamigrering. **Plan-fasen skal verificere bånd-grænserne mod faktiske prod-værdier** (Frankston-regel: gæt aldrig).

## Leverance-rækkefølge (hver del selvstændigt deploybar PR)

1. **2a — Selskabs-opslag komplet** (størst — det ER Resights-billedet): builder-udtræk, datterselskaber i grafen, ejendomme (capped, async), intervaller, udvid, gammel sektion fjernes.
2. **2b — Person-opslag:** ny graf-sektion på person-siden; builder + blade-partial genbruges 1:1.
3. **2c — Ejendoms-opslag:** graf på ejendoms-siden; ejendommen nederst, ejerkæden op.

## Edge cases

- **Store koncerner (85+ ejere):** dybde-bounds + udvid + zoom/pan (bygget).
- **Cykler/diamanter:** dagre + eksisterende cycle-guard; edge-dedup bevares.
- **Ejendom ejet af flere koncern-selskaber:** én node, flere kanter (dedup på BFE).
- **Person uden selskaber / selskab uden ejendomme:** grafen render'es uden det lag — ingen fejltilstand.
- **CVR-andele summer ikke til 100%:** kendt datavilkår, note findes allerede under grafen.

## Performance

- Ejendomme = ét koncern-kald, efter first paint. dagre på ≤40 noder er målt uproblematisk i fase 1.
- Interval-mapning er ren streng-formatering, ingen ekstra kald.

## Test & verifikation

- **Builder-unit-tests pr. indgang:** node/kant-sammensætning, caps, udvid-append, interval-mapning, BFE-dedup — oven på fase 1's suite.
- **Browser-verifikation på den ÆGTE prod-side med konsollen åben** (fase 1's dyreste lektie — standalone-render beviser kun render-logikken, ikke layout-konteksten). `php artisan view:clear` efter enhver Blade-ændring på metis-prod.
- Rollback: hver del-leverance er én PR; 2a kan reverte til fase 1-grafen uden datarisiko (registry-api urørt).

## Non-goals

- Aktieposter- og gæld-lag (fase 3).
- Ændringer i registry-api.
- Radial/cirkulær layout-motor — centrum-effekten opnås hierarkisk.
- Redigering/eksport af grafen.
