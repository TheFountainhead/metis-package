# Graf-filter-chips: selskabs-grafen + privat-ejendoms-lag på person-grafen — design

**Dato:** 2026-07-28 · **Status:** afventer Frederiks review · **Bygger på:** 2a (CompanyStructure, LIVE), 2b (PersonStructure m. chips-mønstret, LIVE), person/property-portfolio-undersøgelsen 28/7.

## Mål

To lag af samme mønt (Frederiks ja 28/7):

**A.** Selskabs-grafen (`Virksomhedsstruktur` på `/lookup/cvr/...`) får filter-chips som person-siden: **"✓ Ejere (N)" / "✓ Datterselskaber (M)" / "✓ Ejendomme (K)"** — vis/skjul lag, øjeblikkelig filtrering.

**B.** Person-grafen får en **tredje chip "✓ Private ejendomme (K)"**: personens privat-ejede ejendomme (Ejerfortegnelsen) hænger direkte på person-noden med ejerandels-%, så én graf viser privat ejerskab + selskabs-ejerskab + roller.

## Datagrundlag (prod-verificeret 28/7)

`RegistryApi::fetchPersonPropertyPortfolioByCpr($cpr)` → `personal_properties[]` med felterne (verificeret mod prod, admin-CPR): `matrikelnummer`, `address`, `city`, `zip`, `public_valuation`, `area_building`, `year_built`, `ownership_share`, `co_owners`, `mortgages`. `summary.personal_property_count` findes.

**Begrænsning (bindende for scope):** rækkerne bærer INGEN BFE og INGEN koordinater ⇒ private ejendoms-noder får hover-kort med vurdering/areal/år/pant/medejere, men **ingen Street View og intet skråfoto-link** i denne omgang. Follow-up-issue i registry-api: udvid payloaden med BFE + lat/lng (så falder foto/skråfoto ud automatisk via de eksisterende kort-stier).

## A. Selskabs-grafens chips

- **Lag:** `owners` (ancestors-kæden), `subsidiaries` (datterselskaber inkl. auto-udvid), `properties` (ejendomme). Alle tre prævalgt. `layers` er rebuild-input på `build()` (nyt parameter, default alle tre — bagudkompatibelt).
- **Builder:** `'owners' ∉ layers` ⇒ `addAncestors` springes over; `'subsidiaries' ∉ layers` ⇒ `addSubsidiaries`; `'properties' ∉ layers` ⇒ `addProperties` + aggregat-rækker udelades (aggregat er ejendoms-afledt). Den søgte virksomhed (rod) vises ALTID.
- **Aldrig-tom-regel:** samme som person-siden — en chip kan ikke fravælges hvis fravalget efterlader nul synlige noder ud over roden; en chip for et tomt lag kan altid. Server-håndhævet i `toggleLayer()` (genbrug person-sidens metode-mønster 1:1 inkl. `$otherCount`-affordance-låsen og `wire:loading.attr` + `wire:target`).
- **Badges (pre-cap, kommenteret som på person-siden):** N = antal ancestors-rækker; M = totalt antal datterselskaber (rekursivt, `countDescendants`-stil); K = portefølje-listens længde.
- **Hentning fortsætter uanset chips** (etableret regel) — chips filtrerer kun bygningen; genaktivering er øjeblikkelig.
- **Chips-UI:** genbrug `.mgraph-chip`-markup/styling fra person-structure.blade (overvej udtræk til delt partial `partials/graph-chips.blade.php` med `$chips`-array — implementerings-valg, ikke bindende).
- `rehydrateBeforeRebuild`/`refreshModel`-stierne er uændrede; `layers` er public state som på person-siden; chip-toggle dispatcher `graph-refit` (mønstret findes).

## B. Person-grafens "Private ejendomme"-chip

- **Nyt lag `private_properties`** i `PersonStructure::$layers` (prævalgt som de andre; aldrig-tom-reglen dækker automatisk et tredje lag via den eksisterende node-tællings-logik).
- **Hentning:** ny `RegistryApi::fetchPersonPropertyPortfolioByCprCached($cpr)` — 5-min TTL, key `metis:person_property_portfolio:`+sha1(cpr) (RÅT CPR ALDRIG i key), fejl caches aldrig (mønstret fra companies-by-cpr). NB: `PersonProperties`-sektionen kalder i dag den ucachede variant på SAMME side — skift den til den cachede, så siden netto IKKE får flere kald (og sektionen får gratis transport-hærdningen fra #119).
- **Fase:** hentes på FØRSTE poll-tick efter skelettet (én request, eget statusfelt `privatePropertiesStatus: pending/loaded/empty/failed`), aldrig i mount (kaldet har historik for cURL-28 — 27/7 13:15). Fejl ⇒ chip viser "(–)", lag udelades, diskret note + genoptages af samme retry-kaskade-regler som de andre faser (`retryStructures`-kaskaden rører den IKKE; egen retry via `retryPrivateProperties()` eller genbrug af fase-3-retry — implementeringsvalg, dokumentér).
- **Noder/kanter:** id `pp:`+sha1(matrikelnummer.'|'.address) (**CPR-frit, BFE findes ikke**), `kind: 'property'` (eksisterende stil/`x-text`-sikkerhed genbruges), label = adresse. Kant person→ejendom med label = `shareLabel(ownership_share)` (50%-sagen fra Travervænget renderer "50 %"). Ingen `style`-felt (solid — det ER ejerskab).
- **Hover-kort:** nye felter i property-card-shapen: `public_valuation` (kroner — verificér enhed mod tabellens visning FØR mapping; "Gæt aldrig"), `area_building` (m²), `year_built`, `mortgage_count` (len(mortgages)), `co_owner_count`. Host-JS `cardRows()` udvides med de nye rækker (lille host-PR); `streetview_url` er fraværende ⇒ eksisterende svOk-gate holder billedet væk af sig selv.
- **Dedup:** en privat ejendom kan teoretisk også optræde i et selskabs portefølje (samme matrikel) — ingen kollision: selskabs-ejendomme bruger `bfe:`-prefix, private `pp:`-prefix. Begge noder kan vises (de repræsenterer to forskellige ejerskaber). Dokumentér som bevidst valg.
- **Caps:** private ejendomme deltager i `total_nodes`-cappen; per-person first-level-cap `person_private_properties => 10` med fold på person-rodens expand (`props:person:root`-id — det eksisterende props-prefix er ubrugt på person-roden).

## Tests (minimum)

- **Builder A:** hvert lag kan udelades enkeltvis (owners/subsidiaries/properties); rod altid til stede; aggregat følger properties-laget; default-layers = fuld bagudkompatibilitet (eksisterende tests uændrede grønne er beviset).
- **Livewire A:** toggle-filtrering; aldrig-tom (selskab uden ejere OG uden datterselskaber/ejendomme → alle chips frit; selskab hvor ét lag bærer alt → låst); badges; hentning fortsætter for skjult lag.
- **Builder B:** pp-noder m. kant-%; pp-id CPR-frit (regex-fixture); caps+fold; kind property.
- **Livewire B:** fase-status inkl. fejl→"(–)"+note; cache-hit (assertSentCount) på tværs af PersonProperties-sektionen og grafen på samme side; enheds-pin for public_valuation mod PersonProperties-tabellens visning.
- Mutations-tjek på de nye guards (etableret disciplin).

## Leverance

Package-PR (builder-layers + begge komponenter + blades + cache-metode + PersonProperties-skiftet + tests) + lille host-PR (cardRows-felter). Host kan deployes før/efter (nye kort-felter er additive; fraværende felter renderes ikke). Prod-verifikation m. konsol på begge sider + registry-api-issue om BFE+koordinater oprettes ved merge.

## Non-goals

Street View/skråfoto for private ejendomme (afventer registry-api-payload), fuldskærms-knap, pant-DETALJER i kortet (kun antal), navne-siden, gæld/aktieposter (fase 3).
