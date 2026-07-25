# Metis: Ejer-relations-graf fase 2 — Resights-billedet + berigelses-lag

**Dato:** 2026-07-25 (revideret efter 3-agent-review + beyond-Resights-brainstorm samme dag)
**Repos:** metis-package (builder/blade) **+ metis host-app** (`resources/js/ownership-graph.js` — dagre-render'en bor dér; fase 2 kræver JS-ændringer, se Leverance)
**Bygger på:** `2026-07-24-ownership-graph-relations-design.md` (fase 1, LIVE på prod 25/7)
**Reference:** Resights-eksporten `Frederik Gregers Dannisgår d Larnæs-portefølje (5).png` (Downloads, 24/7)
**Status:** Design godkendt i sektioner; review-fund indarbejdet; klar til implementeringsplan (2a.1)

## Mål

Fase 1-grafen viser kun ejere OPAD; datterselskaber render'es i gammel org-chart-stil under grafen, og kanter viser enkelttal. Fase 2 leverer to ting:

**2a.1 — Resights-billedet (struktur):**
1. Datterselskaber ind i grafen — nedad, 2 niveauer initialt, dybere via udvid. Gammel DATTERSELSKABER-sektion + "Udfold struktur" fjernes.
2. Ejendoms-noder (BFE + adresse + anvendelse) hængt på ejende selskab. Altid på, capped ~6 pr. selskab, hentet async efter first paint.
3. "Udvid N relationer" / "Vis N ejendomme mere"-affordances.
4. Ejerandels-visning på kanter (interval KUN hvis data kan bære det — se Interval-afsnit).

**2a.2 — Berigelses-laget (bedre end Resights):**
5. **Hover-/tap-kort** pr. node (niveau 2 i info-hierarkiet): ejendom = skråfoto-thumb + AVM-vurdering + seneste handelspris; selskab = egenkapital/resultat/ansatte + website-link (hvis felt findes); alle = link til fuldt opslag. Tap på mobil (løser touch-hullet).
6. **Værdi-aggregat på selskabs-noder**: "13 ejendomme · 10,4 mio. kr." (`property_count`/`property_value_kr` findes i payload).
7. **Signal-markeringer**: små ikoner for *negativ egenkapital* og *nystiftet (<12 mdr.)*. (Høj-LTV-signal udskydes til fase 3, hvor gæld-laget henter tinglysningsdata.)

**Info-hierarki (bærende designprincip):** node = navn + ét nøgletal → hover/tap-kort = billede + 3-4 nøgletal + link → klik = fuldt Metis-opslag. Grafen forbliver let; dybden er ét klik væk.

**Ikke i fase 2** (se Opfølgere/Non-goals): aktieposter+gæld (fase 3), person-opslag (2b), ejendoms-opslag (2c), tidsrejse.

## Beslutninger

| Beslutning | Valg |
|---|---|
| Scope | 2a (selskabs-opslag) i to PR'er: 2a.1 struktur, 2a.2 berigelse. 2b/2c skåret ud som opfølgere (reviewets anbefaling — de var underspecificerede) |
| Ejendomme | Altid på (ingen toggle), cap ~6 pr. selskab, async efter first paint m. engangs-guard |
| Graf-størrelse | Ejere opad som i dag; datterselskaber 2 niveauer ned; ejendomme capped; **samlet node-cap ~120** (derover trunkeres m. udvid) |
| Centrum | Søgt selskab strukturelt i midten (hierarkisk dagre, ikke radial). Auto-center på søgt node KUN ved initial render; ved merge/udvid bevares brugerens zoom/pan-transform |
| Arkitektur | Server-bygget grafmodel via `OwnershipGraphBuilder` — **ren, deklarativ funktion** (se nedenfor) |
| Builder-API | ÉN indgang (selskab) i 2a. Multi-indgang (person/ejendom) designes først når 2b startes — ikke før (YAGNI-fund) |
| Branche på noder | DROPPES i 2a.1 (N+1: 20-40 serielle HTTP-kald før first paint). Kan komme async i 2a.2 sammen med berigelsesdata, hvis et samlet kald kan bære det |

## Arkitektur

### `OwnershipGraphBuilder` — deklarativ, ren funktion (KRITISK review-fund)

`graphModel` genbygges i dag totalt af `pollForUpdates()`. Rå appends fra udvid/ejendoms-merge ville blive **slettet** ved næste poll-completion. Derfor:

```
build(structureData, propertyData, expandedNodeIds, caps): {nodes, edges}
```

- **Alle stier** (mount, enrichment-poll, ejendoms-fetch-complete, udvid-klik) genbygger `graphModel` gennem builderen. Ingen sti muterer modellen direkte.
- Udvid = `expandedNodeIds`-set + rebuild → **idempotent by construction** (dobbeltklik/dobbelt-request = no-op).
- Deterministisk: samme input → samme node-id'er (person-id-stabilitet på tværs af rebuilds følger gratis).
- Builderen er **ren transformation** — ingen HTTP. RegistryApi-kald sker i komponenten/fetcher-lag. Unit-tests er rene array-tests, ikke HTTP-fakes.
- Fase 1's `seen`/`edgeSeen`-dedup, orphan-stub-pass og `ownedTargetId`-normalisering flytter med ind og anvendes globalt på tværs af lag. Centrum-id er parameter (ikke hardkodet `'searched'`).
- Placering: `src/Services/`. Bevares i komponenten: `enriching`/poll, `$graphModel` som public property (`$wire.$watch` kræver det), historical-owners-visningen (læser `$owners`).
- **State-sanering** (samme ombæring): rå `$ancestors`/`$subsidiaries` ophører som public Livewire-state — komponenten holder builder-input-nøgler (query, expandedIds, propertiesLoaded) + `graphModel`. `fetchCompanyStructure` caches i RegistryApi (samme `Cache::remember`-mønster som portfolio), så deklarative rebuilds ikke koster nye API-kald.

### Datakilder (selskabs-opslag, alle eksisterende endpoints)

| Lag | Kilde | Verificeret |
|---|---|---|
| Ejere opad | `fetchCompanyStructure` (ancestors, som i dag) | ✅ fase 1 |
| Datterselskaber | samme kald — `getSubsidiariesTree` leverer **fuldt nested træ (dybde ≤5)**; "2 niveauer + Udvid N" er ren metis-side trunkering, og N beregnes fra det allerede-hentede træ | ✅ verificeret i review |
| Ejendomme | `company/{cvr}/property-portfolio` (koncern-kald) | ⚠️ plan-verifikation: (a) ejer-cvr pr. ejendom i payload, (b) 'building'-tilstand ved første kald |
| Berigelse (2a.2) | AVM (`fetchValuation`), skråfoto-URL (beregnes ved build), regnskabsnøgletal, website-felt | ⚠️ website-felt skal verificeres i registry-api |

### Lazy-flow

- **First paint:** struktur-lagene (data der allerede hentes). Ejendomme fyres som ét async koncern-kald efter first paint (engangs-guard-flag) → rebuild via builder → `$wire.$watch` re-layouter på plads.
- **'building'-tilstand:** portfolio-kaldet kan returnere building/tomt mens backend bygger → poll få gange med backoff; derefter diskret "ejendomme hentes stadig"-note. Aldrig stiltiende tomt.
- **Udvid:** Livewire-action → tilføj til `expandedNodeIds` → rebuild. Knappen viser loading-state under roundtrip; klik-vs-pan skelnes (mousedown-flytte-tærskel). Ingen kollaps i 2a (noteret som muligt polish).
- **Ejendom ejet af selskab uden for de viste 2 niveauer:** ejendommen vises IKKE før selskabet er udvidet ind (ejendomme hænger altid på deres faktiske ejer-node).

### Fjernes (2a.1)

- DATTERSELSKABER-sektionen (gammel org-chart-stil) i `company-structure.blade.php`.
- `toggleOwnerExpansion`/`expandedOwners` (~55 linjer; ingen andre consumers — verificeret).
- **Bevidst trade-off:** "Udfold struktur" på person-ejere viste personens ØVRIGE selskaber. Den mulighed forsvinder fra selskabssiden indtil person-opslag (2b) leverer den rigtigt. Accepteret.
- Graf-markup + `.mgraph`-CSS (~110 inline-linjer) udtrækkes til delt partial i samme ombæring (forudsætning for 2b/2c og alm. hygiejne).

## Node-, kant- & interval-design (frankston-stil)

- **Person-node:** mørk (#2b2333) — uændret.
- **Selskab-node:** sand-kort, Spectral-navn, mono-række `CVR-NUMMER`. 2a.2 tilføjer værdi-aggregat-rækken + evt. signal-ikoner. (Branche: kun hvis 2a.2's samlede berigelses-kald kan levere den uden N+1.)
- **Ejendom-node (ny):** stiplet kant, adresse som titel i ochre (#8a6d1f), mono-rækker `BFE` / `ANVENDELSE` (via `BbrUsageCategory`). Manglende felt → række udelades; manglende adresse → titel "BFE {nr}". **Node-id: `bfe:{nr}`** (BFE kan kollidere numerisk med 8-cifret CVR).
- **Kanter:** tynd rule-linje, label midt på. Ejerskabs-label-reglen (nedenfor) gælder ALLE ejerskabs-kanter, også datter-kanter. Ejendoms-kanter: ingen label (retningen bærer semantikken).
- **JS (host-app):** `layout()` får per-node-type dimensioner (`NODE_W/H` er i dag hardkodede 210×56 — ejendoms-noder og berigede selskabs-noder har andre mål).
- **Hover-/tap-kort (2a.2):** ren Alpine-template over data der allerede ligger i noden (ingen roundtrip ved hover). Skråfoto vises som beregnet URL; ingen eksterne kald fra kortet (website vises som domæne-link, ikke embedded preview).

### Ejerandels-label (revideret efter review — 50%-hullet)

CVR registrerer NOGLE andele som bånd og NOGLE som eksakte værdier. Heuristikken "tal == båndgrænse ⇒ vis bånd" er FORKERT for fx to ejere med præcis 50% hver.

- **Plan-verifikation FØRST:** kan interval-registrering skelnes fra eksakt værdi i rå CVR-data/registry-api (attribut-type e.l.)? Undersøg faktiske prod-værdier (float-repræsentation af 33,33/66,66 inkluderet).
- **Hvis skelnen findes:** interval-bånd vises for interval-registrerede, eksakt tal for eksakte.
- **Hvis IKKE:** kanter beholder eksakt tal som i dag (ingen regression), og interval-visning droppes/udskydes. Vi gætter ikke ("Gæt aldrig").

## Fejltilstande (nyt afsnit — review-krav)

- **null ≠ tom:** portfolio-kald der FEJLER må aldrig ligne "ingen ejendomme" (misinformation i et vurderingsværktøj). Fejl → diskret "ejendomme kunne ikke hentes"-note + retry-mulighed. Tom (verificeret 0 ejendomme) → intet lag, ingen note.
- **Udvid-fejl:** knap går tilbage til normal + lille fejlmarkering; klik igen = retry (idempotent).
- **Enrichment kører stadig:** eksisterende spinner bevares; datter-lag vokser via poll-rebuild som i dag.

## Leverance-rækkefølge

1. **2a.1 — struktur (Resights-billedet):** builder (deklarativ), datterselskaber i grafen, ejendomme async, udvid, label-regel (efter verifikation), gammel sektion fjernes, partial-udtræk, JS-dimensioner. **To repos, koordineret deploy** (package-PR + host-PR); rollback = revert BEGGE. `view:clear` efter Blade-ændringer (fase 1-lektie).
2. **2a.2 — berigelse:** hover-/tap-kort, værdi-aggregat, signal-ikoner (negativ egenkapital, nystiftet), website-link (efter felt-verifikation), evt. branche.

### Opfølgere (kræver egen mini-spec — IKKE besluttede leverancer)

- **2b person-opslag:** åbne spørgsmål fra review: navne- vs CPR-side (CPR-siden har allerede `PersonNetwork` i gammel stil — skal afløses), person-disambiguering (`disambiguatePerson`), first paint-strategi (N tunge kald, 5-15 s), hvilke roller tæller, cap på selskaber, person-centrum-id.
- **2c ejendoms-opslag:** adresse→BFE-resolve (opslag er adresse-drevet; null-BFE findes), ejere uden CVR-kæde (personer/andelsforeninger/offentlige — hvad vises?), én-node-grafer skjules.
- **Fase 3:** aktieposter + gæld-lag (og dermed LTV-signalet).
- **Parkeret idé:** tidsrejse (strukturen på valgt dato — CVR har perioder).

## Performance

- Ejendomme = ét koncern-kald efter first paint. Cache af `fetchCompanyStructure` fjerner rebuild-omkostninger ved actions.
- Node-cap ~120 samlet; dagre-måling ved >40 noder indgår i 2a.1-verifikation (fase 1 målte kun ≤40).
- Payload: graphModel som eneste store public state (efter sanering) — est. 15-30 KB ved 40-80 noder; acceptabelt.

## Test & verifikation

- **Builder-unit-tests (rene array-tests):** sammensætning pr. lag, caps, expandedIds-rebuild (idempotens), label-regel, `bfe:`-dedup, orphan-stubs, node-cap-trunkering.
- **Plan-fasens data-verifikationer (FØR kode):** (1) interval vs. eksakt-skelnen, (2) portfolio-payload: ejer-cvr pr. ejendom + building-adfærd, (3) website-felt i registry-api. To-tre curl-kald.
- **Browser-verifikation på ÆGTE prod-side med konsollen åben** (fase 1's dyreste lektie); `view:clear` efter Blade-ændring.
- Rollback: 2a.1 = revert package-PR + host-PR sammen; registry-api urørt.

## Non-goals

- Ændringer i registry-api (alle lag + berigelser bruger eksisterende endpoints; mangler et felt → featuren udskydes, endpointet ændres ikke i denne fase).
- Radial layout-motor; redigering/eksport af grafen; embedded website-previews (eksterne kald fra hover-kort).
- Touch-pan/pinch på canvas (2a.2's tap-kort giver mobil-adgang til INDHOLDET; fuld touch-navigation er senere polish).
