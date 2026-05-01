# Data-access-matrix: Frankston vs. Resight (1. maj 2026)

**Formål:** Per data-domæne — har Frankston/Registry-API adgang? Med hvilken dækning, friskhed, kilde? Hvad skal vi bygge, købe eller ansøge om for at lukke gappet?

**Kilder:**
- Frankston-side: `registry-api/routes/api/v1.php`, `MEMORY.md` integrations-sektion, `Frankston-master/CLAUDE.md`, `metis-package/src/Services/RegistryApi.php`
- Resight-side: videoframes (010, 020, 027, 030) + tredjeparts artikler

**Vigtigt:** Datadækning ≠ produkt-dækning. Vi har ofte data men har ikke surfaced det i Metis UI.

---

## 1. Domæne-matrix

### 1.1 Ejendoms-stamdata (BBR, MAT, DAR, EBR, VUR)

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **BBR** (bygnings- og boligregister) | ✅ LIVE via Datafordeleren — `bbr/lookup` endpoint, units-tabel med m², byggeår, anvendelses-type | ✅ Anvendt i alle ejendomsdetailer | None | — |
| **MAT** (Matriklen) | ✅ LIVE via Datafordeleren — `map/parcels/{matrikelId}` | ✅ | None | — |
| **DAR** (Adresseregister) | ✅ LIVE — `properties:import-dawa` bulk-import (commit `265720b`) + autocomplete | ✅ | None | — |
| **EBR** (Ejendomsbeliggenhedsregister) | ✅ LIVE via Datafordeleren | ✅ | None | — |
| **VUR** (Offentlig vurdering, 19 års historik) | ✅ LIVE — `valuations/{matrikelId}/history` endpoint | ✅ | Note: ejerlejligheder >100M er ikke vurderet individuelt, kun samlet (Metis-known limitation) | Acceptabel, samme limitation gælder Resight |
| **Skråfoto** | ❌ Ikke i registry-api | 🟡 Sandsynligvis (frame 010 viser kun matrikelkort, ikke skråfoto) | Skråfoto-integration | Datafordeleren WMS skråfoto er offentligt. ~5-7 dage at integrere som tile-layer |
| **Lokalplan** | ✅ LIVE — `plandata/lookup` endpoint | ✅ | None | — |
| **Fredning/heritage** | ✅ LIVE — `heritage/lookup` endpoint | ✅ | None | — |
| **Energimærke** | 🟡 Endpoint findes (`energy/lookup`) men afventer EMOData credentials (ansøgt 5. mar 2026) | ✅ | Credentials-blocker | Følg-op på EMOData ansøgning |
| **Bluespot/oversvømmelse** | ✅ LIVE — Martin tile-layer i Metis (zoom 15+16 slettet pga. disk) | ✅ | Zoom-niveau (cosmetic) | Lav prioritet |
| **Map layers (samlet)** | ~8-10 layers | 600+ | Stor | **Strategisk valg:** ikke kappes 600 layers — Metis fokuserer på de 20-30 mest relevante for kreditor-segmentet (oversvømmelse, jord-forurening, plan-zoner, transport-støj) |

**Konklusion ejendoms-stamdata:** Vi er på paritet eller bedre. Eneste reelle gap er skråfoto (let at lukke) og energimærke (credentials-blocker, ikke teknisk gap).

---

### 1.2 Tinglysning (KRITISK FOR DRAUPNIR-VINKEL)

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **Tinglysningsretten prod-adgang** | 🟡 GODKENDT, afventer IP-whitelisting | ✅ | Whitelisting | Følg-op på Tinglysningsretten |
| **Daglig crawl** | ✅ LIVE — `tinglysning:crawl` med adaptive backoff (commits `957886d`, `e368e11`) | ✅ "Live" prognose i Hjem-widget (frame 020) | None | — |
| **`mortgages` tabel** | ✅ — felter: `creditor`, `principal_amount`, `interest_rate`, `is_active`, `property_id` (`registry-api/app/Models/Mortgage.php`) | ✅ | Klassifikations-felt for `debt_type` (Ejerpantebrev/Afgiftspantebrev/Privat pantebrev) | **Kritisk for F2** — tilføj enum-felt, evt. backfill fra eksisterende data |
| **Pant prioritet (1-12+)** | 🟡 Skal verificeres om felt findes | ✅ Synligt i frame 027+030 | Kan være missing | Verificér `mortgages.priority` kolonne; hvis ikke der, schema-migration |
| **Debitor / Kreditor** | ✅ Begge i `mortgages.debtor`, `creditor` (verificér feltnavne) | ✅ Begge synlige som separate kolonner | None | — |
| **Tinglyst-dato** | ✅ `mortgages.tinglyst_at` (verificér) | ✅ "4. juli 2011" osv. | None | — |
| **Andelsboligbogen** | ❌ Ikke i registry-api så vidt jeg kan se | ✅ (frame 027 tom-state for TONSBAKKEN) | Andelsbolig-gæld | Tinglysningsretten har separat XML-feed for andelsboligbogen — ansøg om |
| **Bilbogen** | ❌ | ✅ (frame 027 tom-state) | Køretøjs-pant | DMR §17 (igangværende ansøgning) → kan bruges. ETA 10-20 uger |
| **Personbogen** | ❌ | ✅ (frame 027 viser sektion bunden) | Person-pant | Tinglysningsretten Personbogen XML — ansøg om |
| **`PropertyTinglysningController::search`** | ✅ LIVE | ✅ tilsvarende | None | — |
| **Tinglysning-DELTA-detektor** | ❌ — `MonitoringService` har kun `transaction` + `ownership_change` (per gap-analysis) | ❌ Heller ikke! (T:497) | **F1's KERNE-FEATURE** | **Skal bygges** — `checkMortgageDelta()` 4-7 dages arbejde |

**Konklusion tinglysning:** Vi har den **vigtigste** del (Fast ejendom = pantebreve i ejendomme) på paritet. Andelsbolig + Bil + Personbog er Resight-only data Metis ikke har. **Det reelle differentiator-arbejde** er IKKE at få mere data — det er at bygge delta-detektoren ovenpå den data vi allerede har.

---

### 1.3 Selskabs-data (CVR, regnskaber, struktur, roller)

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **CVR-grundlag** (Virk.dk) | ✅ LIVE — `FrankstonApi::fetchCompanyInformation()` | ✅ | None | — |
| **Regnskaber** (3+ års nøgletal) | ✅ Memory bekræfter v2.6.0 PDF-source-detection + cache | ✅ | "Designede regnskaber"-kant — Resight fejler her (T:380) | Vi har potentiel fordel her, men ikke verificeret |
| **Roller (direktør, bestyrelse)** | ✅ — `cvr/roles-by-cvr`, `cvr/person-roles` | ✅ | None | — |
| **Reelle ejere** (med dato-tracking) | ✅ — `cvr/cross-ownership`, `cvr/company-structure` | ✅ | None | — |
| **Dato-fra på roller** ("Direktør siden d. 19. juni 2025") | 🟡 Verificér om vi tracker | ✅ (frame 010) | Roll-history-tracking | Sandsynligvis i CVR-data men ikke surfaced |
| **Konkurser / Status** | ✅ via CVR | ✅ ("Aktive/Inaktive/Konkurser" frame 010 widget) | UI surface | Mindre UI-arbejde |
| **Indirect transactions** (ejerskifte via selskab) | ✅ — `IndirectTransactionController` + tabel | ✅ (email-trigger frame 024) | None | — |
| **Skattelister** (åbne) | ✅ — `company/{cvr}/tax` endpoint | Ukendt | Måske unik for Frankston | Verificér Resight har det |
| **Industri/Branche** | ✅ — `cvr/search-by-industry` | ✅ | None | — |
| **Kontakttelefon-opslag** | 🟡 (frame 010 "Klik her for at checke telefonnummer") | ✅ | Hvilken kilde? Sandsynligvis tilkøbt third-party eller crawl af cvrlive.dk | Lavere prioritet — kreditor-segment har egen client-database |

**Konklusion selskab:** Paritet eller bedre på kerne, mindre UI-gaps på dato-historie og status-widgets.

---

### 1.4 Person-data

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **CPR-opslag** | 🟡 KRÆVER CPR — kun muligt i embedded mode (Frankston-master klienter), ikke standalone | ✅ Resight viser personer offentligt (T:418) | GDPR-forskel? | Verificér Resight's hjemmel — er det kun for valgte CPRs der er kunder? Eller offentlig opslag på navn? |
| **Person-søgning på navn** | ✅ — `cvr/person-roles`, `searchPersonByName` | ✅ (frame 010) | None | — |
| **Person-roller** | ✅ | ✅ | None | — |
| **Person-ejede selskaber** | ✅ | ✅ | None | — |
| **Person-ejendomme (direkte + indirekte)** | ✅ — Frankston har 147 ejendomme for Bisgaard-Frantzen-eksempel | ✅ | None | — |
| **Person-watch_type til alerts** | ❌ — backend validator har kun property/postal_code/company | ❌ Ukendt om Resight har det (frame 024 email viser "Brian Nielsen" som fulgt) | **GAP** | Tilføj person watch_type i registry-api MonitoringController validator + checkPerson |
| **Folkeregister / flytte-historik** | 🟡 Kræver CPR | Ukendt | Mulig F11 (køber-profil) datakilde | Lav prioritet |
| **Bestyrelses-tracking historisk** | 🟡 CVR har det, ikke verificeret om vi viser tidligere roller | ✅ ("BESTYRELSESMEDLEM siden d. 4. mar. 2026") | UI surface | Mindre arbejde |

**Konklusion person:** Hovedsageligt UI/feature-paritet, ikke datadækning. Backend skal udvides med person-watch_type.

---

### 1.5 Transaktioner / Handler

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **Property transactions** | ✅ LIVE — `PropertyTransaction` model + `properties/{matrikelId}/transactions` | ✅ 3.5M+ | Coverage-comparison nødvendig | Tjek vores tabel-row-count vs 3.5M |
| **Indirect transactions** (selskabs-skifte) | ✅ LIVE — `IndirectTransactionController` | ✅ (email-trigger frame 024) | None | — |
| **Køber-/sælger-detalje** | ✅ — felter på model | ✅ "JS INVEST, SILKEB..." (frame 020) | None | — |
| **Live transaktionsvolume-prognose** | ❌ Ikke implementeret | ✅ Hjem-widget (frame 020) | Cosmetic widget | Lav prioritet — bygges ovenpå eksisterende data |
| **Lignende handler / similar trades** (kvm-pris, område, type) | 🟡 Ikke fundet i metis-package src/ | ✅ (T:443-446 + screenshot 03) | UI-feature gap | Ny similarity-query + Livewire-component. 3-4 dage |
| **Q2/halvår-prognose** | ❌ | ✅ "Live" widget (frame 020) | Time-series prediction | Cosmetic, P3 |

**Konklusion transaktioner:** Primært UI-features, datagrundlaget er der.

---

### 1.6 Lejeniveau (RESIGHT'S DELVIS-SVAGHED)

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **Boliglejedata** | ❌ Ikke aggregeret i registry-api | ✅ 135.000 observationer (delvist annonce-baseret) | Stor data-gap | **Strategisk valg:** Bygge eller licensere? Resight's data er 50% antagelse — vi kan markedsføre kilde-mærket data |
| **Erhvervsleje-niveau** | ❌ | 🟡 ReData har "Boliglejedata" + Resight har "Leje (Ny)" sidebar-modul | Stor gap | Boligas annoncer + GLR + manuel indberetning |
| **Boliga-annoncer** | ✅ LIVE — `BoligaListing` model + `checkBoligaStaleness` (commit `becdce8`) | Sandsynligvis samme kilde | None | — |
| **Lejedata kilde-mærkning (F8)** | ❌ | ❌ (skjult af Resight!) | Differentiator-mulighed | **Markedsmessig fordel** hvis vi bygger |

**Konklusion lejeniveau:** Vores største data-gap, men også Resights svageste punkt (T:281 "ikke faktuelt"). Strategisk: vi kan IKKE matche 135K observationer på kort sigt, men vi kan markedsføre **kilde-transparens** og **kreditor-segment behøver ikke detaljerede leje-data** (de behøver gælds-data).

---

### 1.7 AVM (Automated Valuation Model) + Credit Scoring

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **AVM estimate** | ✅ LIVE — `avm/estimate`, `avm/portfolio`, `avm/stress-test` | Ukendt | **MULIG FRANKSTON-FORDEL** | Kreditor-segment har behov for AVM, det Resight ikke har som første-klasses produkt |
| **Credit scoring** | ✅ LIVE — `credit/assess`, `credit/batch` | ❌ Ikke i Resight | **EKSPLICIT FRANKSTON-EDGE** | Markedsfør som "credit suite" til kreditor-segment |
| **Stress-test** (rente-stress, LTV-stress) | ✅ LIVE | Ukendt | Mulig edge | Verificér |

**Konklusion AVM/credit:** Vi har features Resight ikke har. Det er en stor del af "kreditor-vinklen" — banker og fonde med pant i fast ejendom skal stress-teste portefølje, det er en LTM-lov-krav.

---

### 1.8 Byggeri / Projekter (RESIGHT'S LOCK-MODUL)

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **Offentlige udbud** | ❌ | ✅ "Byggeleads" lock-modul | Stor data-gap | **SKIP** — Rasmus tester det ikke værd (T:467-485) |
| **Byggetilladelser** | ❌ | ✅ | Skip | Lav prioritet |
| **Projekter under opførelse** | ❌ | ✅ | Skip | Lav prioritet |

**Konklusion byggeri:** Bevidst skip per Rasmus' input. Ingen action.

---

### 1.9 Person-relaterede økonomiske data

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **eSkat (skat-historik for person)** | 🟡 Demo-creds modtaget, FT-registrering 20. maj 2026 | ❌ Ukendt | **MULIG FRANKSTON-EDGE** | Kreditor-vinkel: skat-historie til lån-vurdering |
| **Open Banking AIS** (kontodata) | 🟡 Package bygget, afventer sandbox-creds | ❌ | **KLAR FRANKSTON-EDGE** | Ikke for Metis direkte, men creditor-pakke kan inkludere |
| **Indkomst/lønindberetning** | ❌ | ❌ | None | — |

**Konklusion:** Frankston har en regulerings-trappe (eSkat, AIS) der giver kreditor-data Resight aldrig kommer til at have.

---

### 1.10 DMR (Køretøjer) — niche

| Domæne | Frankston-status | Resight | Gap | Action |
|---|---|---|---|---|
| **DMR §17** (køretøjsregister) | 🟡 Ansøgt, ETA 10-20 uger | ❌ | **METIS KØRETØJER** er separat produkt-spor | Lav koblet til Metis Resight-parity, men kan integreres i Bilbogen-sektion (frame 027) når den lander |

---

## 2. Sammenfatning: Hvor er gappet reelt?

### 2.1 Lukkbare gaps (data eksisterer, mangler kun UI)

| Gap | Effort | P-niveau |
|---|---|---|
| `debt_type` klassifikation på mortgages | ~1 dag (DB-migration + backfill) | P0 (F2-blocker) |
| Pant `priority` kolonne | ~0.5 dag (verificér eller tilføj) | P0 (F2-blocker) |
| `mortgage_change` alert-type + delta-detektor | 4-7 dage | P0 (F1-kerne) |
| Person-watch_type | 1-2 dage (backend) + 0.5 dag (UI) | P1 |
| Lignende handler-modul | 3-4 dage | P1 |
| Hjem-dashboard widgets | 5-7 dage | P2 |
| Lister/følg-kategorier | 3-5 dage | P2 |

### 2.2 Mid-effort gaps (kræver ny pipeline eller integration)

| Gap | Effort | Action |
|---|---|---|
| Skråfoto-tile-layer | 5-7 dage | Datafordeleren WMS — gratis adgang |
| Andelsboligbogen | 1-2 uger | Tinglysningsretten ansøgning + parser |
| Personbogen | 1-2 uger | Tinglysningsretten ansøgning + parser |
| Bilbogen | 2-4 uger | DMR §17 (igangværende) |
| Email-digest service | 1 uge | Ny notification-pipeline (Mail::queue + daglig cron) |

### 2.3 Strategiske valg-gaps (ikke entydigt action)

| Gap | Strategisk vurdering |
|---|---|
| Lejeniveau-data (135K observationer) | **Bygges ikke direkte** — markedsfør i stedet kilde-transparens. Kreditor-segment har lavere behov. |
| Byggeleads/udbud | **Skip per Rasmus** |
| Live transaktionsvolume widget | Cosmetic, P3 |
| 600+ map layers | **Fokusér på 20-30 kreditor-relevante** (oversvømmelse, jord-forurening, plan-zoner) |
| AI-assistent | Lav baseline at slå (Resights "aldrig brugbart" T:539) — vi kan bygge en simpel MCP-server |

---

## 3. Frankston-edges Resight ikke har

Disse er IKKE eksisterende Metis-features, men **datakilder/produkter Frankston har** der kan inkluderes i en kreditor-pakke:

1. **AVM** (`avm/estimate`, portfolio, stress-test) — automatisk valuation, kreditor-værktøj
2. **Credit scoring** (`credit/assess`, batch) — score private + selskaber
3. **Open Banking AIS** (Mastercard/Aiia) — bankkontodata, ikke til Metis direkte men til creditor-pakke
4. **eSkat** (kreditformidler-vej fra 20. maj 2026) — skat-historik for låne-vurdering
5. **Mortgage delta-engine** (kommende) — tinglysning-monitor som første i markedet
6. **Frankston-master integration** — hvis vi bygger Metis ind i Frankston-master kan kunder få porteføljestyring + ejendomsdata samme sted (Resights "Lister" på steroider)
7. **Open API til kreditorer** — Resight har ingen offentlig API. Frankston har Registry-API klar med 40+ endpoints + OpenAPI 3.1 spec.

---

## 4. Konklusioner (Fase B3)

1. **Vi har ~85-90% data-paritet** med Resight. Det er ikke datadækning der gør forskellen.
2. **De 3-4 reelle data-gaps** (skråfoto, andelsbolig, person, bil) lukker på 4-8 uger samlet hvis vi prioriterer.
3. **Den eneste store strategiske data-gap er lejeniveau** — og den vælger vi bevidst at IKKE lukke fordi Resight's egen data er antaget og kreditor-segmentet ikke kræver det.
4. **Vi har 5+ data-edges** (AVM, credit, AIS, eSkat, tinglysning-delta, Open API) Resight aldrig kommer til at have.
5. **Den faktiske bottleneck er ikke data, det er UI/UX/sticky-features** der får brugeren til at skifte. Det matcher Kristians citat T:776: *"Det Resight gør, er jo ikke revolutionerende på nogen som helst måde, men det er jo bare fedt at kan se alt på den måde, de kan se det på."*

**Strategisk implikation:** Metis skal vinde på *positionering + UX + sticky-monitoring*, ikke på *bredere datadækning*. Vi skal være "kreditorernes Resight" og det første tinglysning-monitor-produkt på markedet.
