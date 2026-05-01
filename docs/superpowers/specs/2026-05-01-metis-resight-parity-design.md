# Metis Resight-parity & Creditor-edge — Design (1. maj 2026)

**Status:** Design — godkendelse afventer (skal præsenteres for Frederik morgen efter natten 30/4 → 1/5).

**Brainstorm-input:**
- Draupnir-mødetranscript 29. apr (`Draupnir 2026.04.29.txt`, 840 linjer)
- Skærmoptagelse `Draupnir + Resight.mp4` (27 min) — 80 frames ekstraheret, 5 nye Resight-screens analyseret
- Verificeret kode-status i `metis-package/main` + 19 PRs siden mødet + `registry-api/main`
- Web-research: byensejendom.dk, redata.dk/produkter, redata.dk/priser, bygge-anlaegsavisen.dk

**Foregående artefakter i samme bundle:**
- `docs/user-research/2026-04-29-draupnir-resight/phase-A-findings.md` (verified-vs-assumed)
- `docs/user-research/2026-04-29-draupnir-resight/gap-analysis.md` (per F1-F11 status)
- `docs/user-research/2026-04-29-draupnir-resight/competitor-analysis.md` (Resight + ReData)
- `docs/user-research/2026-04-29-draupnir-resight/data-access-matrix.md` (per data-domæne)

---

## 1. Executive summary

Metis er pr. 1. maj 2026 ~85% Resight-paritet på data, ~50% UI-paritet, og har bygget UI til de to vigtigste differentiator-features (F1 gælds-alerts, F2 omvendt søgning). Men begge fejler i prod pga. en route- og schema-mismatch mellem `metis-package` og `registry-api`. Den faktiske kerne-feature (mortgage-delta-detektor) er **ikke** skrevet endnu.

**Anbefalet strategisk positionering:** *"Kreditorernes Resight"*. Resight er bygget for *investorer*, *bygherrer* og *mæglere* — ikke for dem der har **lånt penge til** fast ejendom. Det er et åbent segment på 250-400 organisationer (banker, realkredit, ejendoms-credit-fonde, advokater, factoring) med 600-1.500 brugere, der har specifikke behov Resight aldrig har prioriteret: tinglysning-delta-overvågning, omvendt gælds-søgning, AVM, credit scoring, stress-test af pant-portefølje, integration til Open Banking.

**Anbefalet sprint-rækkefølge (12 uger til "Rasmus klar til skifte"):**

| Sprint | Uger | Outcome | Måling |
|---|---|---|---|
| **0. Backend-rebrand** | uge 1 | F1+F2 kalder rigtig backend, mortgage-delta-engine implementeret | Pilot-token user kan følge ejendom og se alert dagen efter en tinglysning |
| **1. F2-paritet** | uge 2 | owner_type + debt_type + CSV-eksport i debt-search | Rasmus kan eksportere "10%+ rente, ejet af selskab, 8000-9999 postnumre" som CSV |
| **2. F1+F5 dybde** | uge 3-4 | Email-digest, person-watch_type, alert-detalje-side, kategoriserede lister | Rasmus får daglig 08:00 email med "kommer fra Frankston" subject + 1+ rigtig tinglysning-delta |
| **3. P1 paritet** | uge 5-7 | Selskabs-overblik (F3), ejendomsdetalje-LTV (F10 udvidet), lignende handler (F6) | Visuel paritet med Resight på 5 superbruger-flows |
| **4. UX kvalitet** | uge 8-9 | Hierarkisk ejer-vis (F9), kilde-mærkning på data (F8 light), Lister-feature, Hjem-widgets | Rasmus' kvalitative feedback bekræfter "lige så god eller bedre" |
| **5. Pilot lock-in** | uge 10-12 | 2-3 ekstra kreditor-piloter (banker/credit-fonde), case-historie publiceret | 3 LOI'er for 3 kreditor-segmenter |

---

## 2. Strategisk positionering

### 2.1 Hvorfor "Kreditorernes Resight"?

Resight har 1.300 kunder og 4.000 brugere fordelt på 8 segmenter. INGEN af dem er **bygget til** kreditorer. Kreditorer **bruger** Resight, men:
- Får ikke alerts om udlæg / nye pantebreve (Rasmus, T:497)
- Mangler omvendt gælds-søgning (Frederik's demo gav Rasmus "wow")
- Mangler AVM/credit scoring/stress-test integreret
- Mangler kreditor-rapport-format

Frankston har:
- ✅ Mortgage-delta-detektor på vej (Rasmus' single sticky-feature)
- ✅ AVM API LIVE (`registry-api/avm/*`)
- ✅ Credit Scoring API LIVE (`registry-api/credit/*`)
- 🟡 Open Banking AIS package bygget (afventer sandbox)
- 🟡 eSkat på vej (kreditformidler-trin 20. maj 2026)
- ✅ Tinglysning daglig crawl
- ✅ 40+ endpoint OpenAPI-spec

**Det er en B2B SaaS positionering uden direkte konkurrence i 2026.**

### 2.2 Hvorfor IKKE forsøge bredt Resight-konkurrence først?

Tre grunde:
1. **Bias-mur** (T:365): Investor-segmentet er stærkt loyale. ReData er anti-eksemplet.
2. **Data-bredde**: Vi mangler 135K leje-observationer + byggeleads — to af Resights kerne-modules. Vi vinder ikke et bredt slag.
3. **Distributions-fordel**: Frankston har allerede relationer til Draupnir (kreditor) + bestyrelsesposter i kreditfonde + bank-partnerskaber via Mastercard. Det er ikke et nyt netværk.

Kreditor-segmentet kommer først. Investor-segmentet kommer senere når kreditor-cases er beviset.

### 2.3 "Kreditor"-personaen

| Persona | Antal i DK | Pain-point Metis løser | Pris-elasticitet |
|---|---|---|---|
| **Ejendoms-credit-fond** (Draupnir m.fl.) | 20-40 | Negative pledge violation detection | Høj — DKK 25-50K/bruger acceptabel |
| **Bank credit officer** (ejendomslån) | 70+ banker × 5-20 = 350-1.400 | Portfolio risk monitoring | Mid — DKK 15-30K/bruger |
| **Realkredit-arbejdsgrupper** | 6-7 institutter × 50-200 = 300-1.400 | Værdi-overvågning + LTV-stress | Høj — kan godt 30-50K/bruger |
| **Advokat (insolvens, fast ejendom)** | 100+ × 1-5 = 100-500 | Konkurs-screening + due diligence | Mid — DKK 15-25K/bruger |
| **Family office med ejendoms-PE** | 50-80 × 1-5 = 50-400 | Investor-PE features + monitoring | Høj — DKK 25-50K/bruger |
| **Forsikring (ejendomsdækning)** | 5-10 × 5-20 = 50-200 | Risiko-vurdering | Mid |

**Konservativt scenario:** 30% capture × 1.000 brugere × 30K DKK ARPU = **DKK 9M ARR** alene fra kreditor-segment over 3 år. Optimistisk: 50% capture × 1.500 × 35K = **DKK 26M ARR**.

---

## 3. Arkitektur

### 3.1 Komponent-diagram

```
┌────────────────────────────────────────────────────────────────┐
│                      metis-package (Composer)                   │
│                                                                 │
│  Standalone (metis.frankston.io) + Embedded (Frankston-master) │
│                                                                 │
│  Livewire components:                                           │
│  - Search, Lookup, MapPanel, EmailGate                          │
│  - DebtSearch (F2) ← UI built, backend mismatch                 │
│  - AlertsInbox (F1) ← UI built, backend mismatch                │
│  - FollowButton (F1+F5) ← UI built, person-type missing         │
│  - PortfolioTinglysningTable (F-new) ← TO BUILD                 │
│  - SimilarTradesTable (F6) ← TO BUILD                           │
│  - HierarchicalOwnerTree (F9) ← TO BUILD                        │
│  - PropertyDetailLtv (F10) ← TO BUILD                           │
│                                                                 │
│  Services: RegistryApi (HTTP wrapper), SearchDetector,          │
│            CompanyEmailResolver, LeadNotifier                   │
└────────────────────────────────────────────────────────────────┘
                              │
                              ▼ HTTP /v1/*
┌────────────────────────────────────────────────────────────────┐
│                    registry-api (Laravel)                       │
│                                                                 │
│  Controllers (existing):                                        │
│  - PropertyTinglysningController (search, search-by-cpr)        │
│  - PropertyController (search, mortgages, transactions, ...)    │
│  - CvrController (8 search/lookup methods)                      │
│  - MonitoringController (watchlists CRUD + alerts) ← MISMATCH   │
│  - MortgageSearchController (search, creditors, stats)          │
│  - AvmController, CreditController                              │
│                                                                 │
│  Services:                                                      │
│  - MonitoringService::run() ← MISSING checkMortgageDelta()      │
│  - Tinglysning crawl (daily, adaptive backoff)                  │
│                                                                 │
│  Models:                                                        │
│  - Watchlist (watch_type, watch_value, alert_types[])           │
│    ← validator missing 'person', alert_types missing            │
│       'mortgage_change'/'new_lien'                              │
│  - Alert, Mortgage, Property, PropertyTransaction               │
│    IndirectTransaction, Company, BoligaListing                  │
│                                                                 │
│  DB: PostgreSQL + PostGIS, Martin tile server                   │
└────────────────────────────────────────────────────────────────┘
                              │
       ┌──────────────────────┼─────────────────────────┐
       ▼                      ▼                         ▼
┌─────────────┐      ┌─────────────────┐      ┌───────────────────┐
│ Tinglysning │      │ Datafordeleren  │      │ CVR / Virk.dk     │
│ daglig crawl│      │ BBR/MAT/DAR/VUR │      │ (CompanyInfo, ...) │
└─────────────┘      └─────────────────┘      └───────────────────┘
```

### 3.2 Den kritiske backend-rebrand

`registry-api`'s `monitoring/*` præfiks skal væk. Watchlists/alerts er en **bruger-feature**, ikke en operationel monitoring-feature. Beslutning: rebrand fra `/v1/monitoring/*` → `/v1/*`. Dette er en breaking change for ingen kendte konsumenter (controlleren er ikke i brug pr. 1. maj — `monitoring/*` blev bygget før metis-pakken og aldrig brugt).

**Migrations-plan:**
1. Tilføj nye routes: `Route::resource('watchlists', ...)`, `Route::get('alerts', ...)`, `Route::patch('alerts/{id}/read', ...)`
2. Behold `monitoring/*` som alias i en uge
3. Tilføj `POST /v1/watchlists/check-batch` (eksisterer slet ikke endnu)
4. Tilføj `POST /v1/debt-search/export-link` (CSV-generering via signed URL → S3)
5. Alias `GET /v1/debt-search` → `MortgageSearchController::search` med udvidet schema
6. Slet `monitoring/*` efter 1 uge når metrics bekræfter ingen bruger ramler

---

## 4. Feature-designs (10 prioriteter med data-model + UI-skitse)

### F1.0: `mortgage_change` alert-engine (P0 — kerne-differentiator)

**Som** ejendoms-kreditor med negative pledge / pantsætningsforbud
**Vil jeg** automatisk få besked når der tinglyses nye pantebreve, udlæg eller andre rettigheder på ejendomme jeg følger
**Så jeg** kan opdage default eller pantsætningsforbud-overtrædelser samme dag

#### Datamodel

Nye felter på `mortgages` (registry-api):
```sql
ALTER TABLE mortgages ADD COLUMN pant_type VARCHAR(50);  -- ejerpantebrev | afgiftspantebrev | privat_pantebrev | realkredit | udlaeg | retsanmaerkning
ALTER TABLE mortgages ADD COLUMN priority INTEGER;        -- 1-N pant-rang
ALTER TABLE mortgages ADD COLUMN tinglyst_at DATE;        -- tinglysningsdato (verificér om ikke allerede der)
```

Backfill fra eksisterende tinglysning-data (heuristik på `creditor` + `principal_amount`-mønstre).

Ny mortgage-snapshot-tabel for delta-detektion:
```sql
CREATE TABLE mortgage_snapshots (
  id SERIAL PRIMARY KEY,
  property_id INTEGER NOT NULL,
  snapshot_date DATE NOT NULL,
  mortgage_id INTEGER NOT NULL,
  pant_type VARCHAR(50),
  priority INTEGER,
  principal_amount BIGINT,
  interest_rate DECIMAL(5,2),
  creditor VARCHAR(255),
  debtor VARCHAR(255),
  is_active BOOLEAN,
  UNIQUE (property_id, snapshot_date, mortgage_id)
);
```

Daily snapshot-job ovenpå `mortgages`-tabel for hver fulgt ejendom.

Watchlist alert-types udvidelse i `MonitoringController.php:23`:
```php
'alert_types.*' => 'string|in:transaction,ownership_change,valuation,new_listing,mortgage_change,new_lien,creditor_change,principal_change,annual_report'
```

#### MonitoringService::checkMortgageDelta()

Pseudokode:
```php
protected function checkMortgageDelta(Watchlist $watchlist): int
{
    $today = now()->startOfDay();
    $yesterday = $today->copy()->subDay();
    
    $todaySnap = MortgageSnapshot::where('property_id', $watchlist->watch_value)
        ->where('snapshot_date', $today)->get();
    $ydaySnap = MortgageSnapshot::where('property_id', $watchlist->watch_value)
        ->where('snapshot_date', $yesterday)->get();
    
    $diff = $this->diff($ydaySnap, $todaySnap);
    
    foreach ($diff['added'] as $newMortgage) {
        $alertType = match (true) {
            in_array($newMortgage->pant_type, ['udlaeg', 'retsanmaerkning']) => 'new_lien',
            default => 'mortgage_change',
        };
        $priority = match ($alertType) {
            'new_lien' => 'high',
            default => 'medium',
        };
        Alert::create([
            'watchlist_id' => $watchlist->id,
            'alert_type' => $alertType,
            'priority' => $priority,
            'title' => "Ny tinglysning: {$watchlist->displayLabel}",
            'description' => sprintf('%s på %s DKK fra %s, prioritet %d',
                ucfirst($newMortgage->pant_type),
                number_format($newMortgage->principal_amount / 100, 0, ',', '.'),
                $newMortgage->creditor, $newMortgage->priority),
            'metadata' => [
                'property_id' => $watchlist->watch_value,
                'mortgage_id' => $newMortgage->id,
                'before' => null,
                'after' => $newMortgage->toArray(),
            ],
        ]);
    }
    
    foreach ($diff['removed'] as $oldMortgage) { /* tilsvarende */ }
    foreach ($diff['changed'] as $change) { /* principal_change / creditor_change */ }
    
    return count($diff['added']) + count($diff['removed']) + count($diff['changed']);
}
```

#### Acceptance criteria

1. Bruger med pilot-token kan følge en BFE → en row i `watchlists` med `watch_type=property, watch_value={bfe}, alert_types=['mortgage_change','new_lien','transaction']`
2. Når en ny tinglysning lander dagen efter på den BFE, kører cron-jobbet og opretter en Alert med korrekt type + priority
3. /alerts inbox viser alert'en med "Vis før vs nu"-toggle der diff'er metadata.before vs metadata.after
4. Email-digest sendes 08:00 med subject "Frankston: 1 ny pant og 2 andre på dine 5 fulgte ejendomme"
5. Test: simuler ny `mortgage`-row → cron → alert i inbox → verificér priority og description-format

#### Estimat
- Schema-migration + backfill: 1 dag
- MonitoringService::checkMortgageDelta + snapshot-job: 3 dage
- Tests + verifikation: 1 dag
- Email-digest service: 2 dage
- **Total: 7 dage**

---

### F1.1: Schema- og route-rebrand (P0 — blocker for F1+F2)

**Action:** Backend rebrand fra `monitoring/*` → top-level. Tilføj `check-batch` og `debt-search`-aliaser. Udvid validators.

**Acceptance criteria:**
1. `GET /v1/watchlists` returnerer 200 (alias eller renamed)
2. `POST /v1/watchlists` med `watch_type=person` accepteres
3. `POST /v1/watchlists/check-batch` med items[] returnerer is_followed-bool array
4. `GET /v1/alerts?priority=high` filtrerer
5. `POST /v1/debt-search?owner_type=company&debt_type=ejerpantebrev` returnerer filtreret resultat

**Estimat:** 2-3 dage. Inkluderer backend-tests + metis-package smoke-test efter rebrand.

---

### F2.1: Owner-type + debt-type filtre + CSV-eksport (P0)

**Datamodel:** kræver `pant_type` (fra F1.0) + ny `properties.primary_owner_type` (selskab|privatperson) — kan udledes fra `property_owners` join.

**MortgageSearchController.search udvidelse:**
```php
'owner_type' => 'nullable|string|in:company,person',
'debt_type'  => 'nullable|string|in:ejerpantebrev,afgiftspantebrev,privat_pantebrev,realkredit,udlaeg,retsanmaerkning',
```

```php
if ($validated['owner_type'] ?? null) {
    $query->whereHas('property.primaryOwner', fn ($q) => 
        $q->where('owner_type', $validated['owner_type'])
    );
}
if ($validated['debt_type'] ?? null) {
    $query->where('pant_type', $validated['debt_type']);
}
```

**CSV-eksport endpoint (`POST /v1/debt-search/export-link`):**
- Validér samme filter-schema
- Generér CSV (1-100K rows max)
- Upload til S3 (presigned URL, 1 time gyldighed)
- Returnér URL + estimated_rows

**Acceptance criteria:**
1. Filter "ejet af selskab" + "ejerpantebrev" + "8000-9999 postnummer" + "rente over 8%" returnerer kun matches
2. CSV downloades korrekt og har headers: Adresse, Selskab/Person, Hovedstol, Rente, Pant-type, Tinglyst-dato, Kreditor, Debitor

**Estimat:** 2-3 dage (1 for filtre, 1-2 for CSV-pipeline).

---

### F1.2: Email-digest service (P1)

**Som** bruger med flere fulgte entiteter
**Vil jeg** modtage daglig 08:00 email med kun-relevante alerts samlet
**Så jeg** ikke drukner i in-app notifications

**Datamodel:** `users.notification_preferences` (json) med default = `{daily_digest: true, hour: 8}`.

**Pipeline:** Cron 08:00 → for each user → gather alerts since last digest → render email → SES. Email-template:
```
Subject: Frankston Metis: [N] ændringer på dine [M] fulgte entiteter

Hej [navn].

Vi har registreret [N] ændringer i dag, hvor [P] er højt-prioritet (udlæg/retsanmærkninger).

🔴 Højt prioritet
- Roligedsvej 1, 4941 Bandholm: nyt udlæg 3,5 mio DKK fra Skattestyrelsen
- Brian Nielsen: ny rolle som direktør hos NEW HOLDING ApS

🟡 Mellem prioritet  
- Tonsbakken 12-14 ApS: ny pantebrev 9,8 mio DKK fra Realkredit DK
- ...

[Se alle i Metis]
```

**Acceptance criteria:**
1. Bruger med 5 fulgte ejendomme får 1 email/dag når der er aktivitet
2. Ingen email når der er nul ændringer (eller weekly summary toggle)
3. Email har deep-link til alert-detalje

**Estimat:** 3-5 dage (mailer + template + cron + opt-out).

---

### F3: Selskabsforside med widgets (P1 paritet)

Match Resights frame 010-style overblik på selskabs-side.

**UI-skitse (Livewire component `CompanyOverview`):**
```
┌────────────────────────────────────────────────────────────────┐
│ TONSBAKKEN 12-14 ApS                       [Rapport][Følg][Liste]│
│ CVR 28963610  •  Svanemøllevej 25, 2100 København Ø  •  Aktiv  │
├────────────────────────────────────────────────────────────────┤
│ [Overblik] Portefølje  Tinglysning  Regnskab  Roller  Historik │
├────────────────────────────────────────────────────────────────┤
│  ┌──────────────┐  ┌──────────────┐  ┌─────────────────────┐  │
│  │ Nøglepersoner│  │ Nøgletal '24 │  │  Map med pins        │  │
│  │ Direktør:    │  │ Omsætn: 12,3 │  │  ┌─┐                  │  │
│  │  - L. Nielsen│  │ Balance: 89,1│  │  │ │  3 ejendomme    │  │
│  │ Bestyrelse:  │  │ EK: 24,7     │  │  └─┘                  │  │
│  │  - K. Hansen │  │ [3-år graf]  │  │                       │  │
│  └──────────────┘  └──────────────┘  └─────────────────────┘  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Anvendelses-fordeling pie chart (Detail 60% / Bolig 30%) │  │
│  └──────────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────────┐  │
│  │ Quick-links: [Regnskab.pdf] [Tinglysning] [Handelshistorik]│  │
│  └──────────────────────────────────────────────────────────┘  │
└────────────────────────────────────────────────────────────────┘
```

**Acceptance criteria:**
1. Selskabsside loads alle widgets parallelt (lazy mount per widget)
2. Nøgletal-graf 3 år trækkes fra cached regnskaber
3. Pie chart bruger BBR `usage_code`-fordeling vægtet på areal
4. Map: Leaflet med pins for hver ejendom + tooltip = adresse
5. Mobile-responsive: stack widgets vertikalt under 768px

**Estimat:** 3-5 dage.

---

### F6: Lignende handler-modul (P1)

**Som** investor/kreditor på ejendomsdetalje-side
**Vil jeg** se 5-10 sammenlignelige handler i området
**Så jeg** kan vurdere transaktions-pris i kontekst.

**Datamodel:** ny query på `property_transactions` joinet med `properties` filtreret på:
- Samme postnummer
- ±20% areal
- Samme `usage_code` (BBR-anvendelse)
- Sidste 24 mdr
- Order by `transaction_date DESC` limit 10

**UI-skitse:** ny `Sections/SimilarTrades.php` på `/lookup/address/*`-siden.

**Acceptance criteria:**
1. Tabel med kolonner: Adresse, Areal, Pris, kr/m², Dato, Anvendelse
2. Sortering på kvm-pris default DESC
3. "Vis flere"-knap efter 5 rows
4. Empty state hvis <3 matches: "For få lignende handler i området"

**Estimat:** 3-4 dage.

---

### F9: Hierarkisk ejer-visualisering (P2 — kvalitets-edge)

**Som** investor/kreditor der researcher en kompleks ejer-struktur
**Vil jeg** se moder → datter → barnebarn som kollapsibel træ
**Så jeg** ikke drukner i Resights spider.

**UI-skitse (Livewire component `OwnerHierarchy`):**
```
▼ HORNHAVER INVESTMENTS ApS (CVR 12345678)  [aktive: 2]
  ├─ Direktør: Rasmus Hornhaver Mønstergård Nielsen (siden 19. juni 2025) [100%]
  ├─ ▼ DRAUPNIR INVESTMENT ADVISORS A/S (datter, 100%)
  │    ├─ Bestyrelsesmedlem: ...
  │    └─ ▶ Datterselskaber (3) [klik for at vise]
  └─ ▶ Andre selskaber (5) [klik for at vise]
```

**Toggle-knap øverst:** "Spider [○] Hierarki [●]" — bevar Resight-spider som option.

**Acceptance criteria:**
1. Træ renderer på samme `cvr/company-structure`-data
2. Default vis kun primær gren (>50% ejerskab eller direktør-forbindelse)
3. Sekundære grene bag "▶"-toggle
4. Mobile-friendly (single column)
5. Performance: max 200 noder før "vis flere"-cap

**Estimat:** 4-5 dage.

---

### F10 udvidelse: Ejendomsdetalje LTV (P1)

PR #19 lavede portefølje-tabel-kolonner. Mangler **ejendomsdetalje-view**.

**UI-skitse på `/lookup/address/*`:**
```
┌─────────────────────────────────────────────────────────────┐
│ Bredgade 40, 1260 København K                               │
├─────────────────────────────────────────────────────────────┤
│ Off. vurdering 2024:  84,2 mio DKK                          │
│ Tinglyst hovedstol:   389,1 mio DKK  (5x vurdering)         │
│ LTV-indikator:        🔴 Høj (462%)                         │
│                                                             │
│ ⓘ Bemærk: Tinglyst hovedstol ≠ aktuel restgæld. Hovedstol  │
│   er det maksimale lånebeløb pantebrevet sikrer; faktisk    │
│   restgæld kan være lavere.                                 │
└─────────────────────────────────────────────────────────────┘
```

**LTV-farver:**
- 🟢 grøn: <60%
- 🟡 gul: 60-80%
- 🟠 orange: 80-120%
- 🔴 rød: >120% (sikkerheds-overshoot)

**Acceptance criteria:**
1. Disclaimer altid synlig (ikke kun tooltip)
2. Når vurdering = null (ejerlejlighed >100M), vis "Ikke offentlig vurderet" + LTV beregnes IKKE
3. Mobile: stack vertikalt

**Estimat:** 1-2 dage.

---

### F-NEW: Portfolio-Tinglysning-tab på selskabs-side (P0 KILLER VIEW)

**Inspiration:** Frame 030 (Resight MiMo Invest porteføljeniveau-tinglysning).

**Som** kreditor med pant i et holding-selskabs portefølje
**Vil jeg** se ALLE pantebreve på tværs af alle ejendomme i én flat-list
**Så jeg** kan vurdere holdingens samlede gælds-eksponering på 30 sek.

**UI-skitse (ny tab på selskabs-side):**
```
[Overblik] Portefølje  ►Tinglysning◄  Regnskab  ...

┌─────────────────────────────────────────────────────────────┐
│ Total tinglyst hovedstol: 247,3 mio DKK  •  18 pantebreve   │
├─────────────────────────────────────────────────────────────┤
│ Adresse    Type        Pri Debitor      Kreditor    Hovedstol │
├─────────────────────────────────────────────────────────────┤
│ Roligedsv… Ejerpant.   6  Mimo Hotel    Mimo Hotel  8,2M DKK │
│ Roligedsv… Afgiftspan. 8  -             Realkredit  16,7M DKK│
│ Roligedsv… Afgiftspan. 9  -             Landsbygge.. 1,9M DKK│
│ Fragevej…  Ejerpant.   5  Mimo Frage…   Mimo Frage… 6,0M DKK │
│ ...                                                          │
│                                                              │
│ [📥 Sampantskema] [⚠️ Følg ændringer på alle 18]             │
└─────────────────────────────────────────────────────────────┘
```

**Acceptance criteria:**
1. Tab loader alle ejendomme i selskabs-portefølje + alle deres pantebreve i én tabel
2. Sortering på hovedstol DESC default
3. "Følg ændringer på alle 18"-knap → batch-create watchlists for alle BFE'er
4. Sampantskema-eksport (CSV/Excel)
5. Performance: server-side pagineret hvis >100 rows

**Estimat:** 3-4 dage. **Dette er den feature der får kreditor-segmentet til at gispe.**

---

### F-NEW2: Hjem-dashboard med widgets (P2 — visuel paritet + sticky)

Match frame 020 personalized dashboard.

**Widgets at implementere:**
1. **Senest fulgt** — list af 5 senest tilføjede follow-items
2. **Seneste alerts** — kort liste af 5 unread alerts med priority-badges
3. **Tinglysnings-volume** — 30-dages chart over hvor meget pant der er tilskrevet i DK + dit segment-area
4. **Kreditor-prospect** — 3 ejendomme der matcher gemt søgning (hvis der er en)
5. **Tilføj widget** — placeholder ("Vi har flere widgets på vej")

**Acceptance criteria:**
1. Hjem rendrer kun når user-token er sat (ellers gå til /search)
2. Hver widget loads parallelt (independent component)
3. Widgets er placerbare (drag-and-drop) — P3
4. Mobile-stack vertikalt

**Estimat:** 5-7 dage. **P2 fordi visuel paritet hjælper men er ikke kerne-differentiator.**

---

### F-NEW3: Lister + følg-kategorier (P2)

Match Resights "Lister"-feature (frame 020 sidebar + widget).

**Datamodel:**
```sql
CREATE TABLE follow_categories (
  id SERIAL PRIMARY KEY,
  user_id INTEGER NOT NULL,
  name VARCHAR(255) NOT NULL,
  created_at TIMESTAMP
);
ALTER TABLE watchlists ADD COLUMN follow_category_id INTEGER NULL;
```

**UI:** "Mine lister" på sidebar — klik = filtreret /alerts-view.

**Use case for kreditorer:** "Lån 2024", "Lån 2025", "Workout-pipeline", "Default-watch", "Refinansierings-prospects".

**Estimat:** 3-4 dage.

---

### F8 light: Kilde-mærkning på data (P2)

Markedsmessig vinkel mod Resight (T:281 antagelses-svaghed).

**Implementering:** badge-komponent `<x-data-source-badge type="indberettet|estimat|modelleret" />` — anvendes hvor leje-data, vurdering, AVM-tal vises.

**Acceptance criteria:**
1. Hver data-værdi der ikke er direkte indberettet har badge
2. Tooltip forklarer kilde + dato + accuracy

**Estimat:** 2-3 dage. P2 fordi det er mere et marketing-spil end stand-alone feature.

---

## 5. Sprint-rækkefølge (12 uger)

### Sprint 0 (uge 1) — Backend foundation
**Mål:** F1+F2 fungerer ende-til-ende. Pilot-token → følg ejendom → alert dagen efter.

| Task | Owner | Dage |
|---|---|---|
| Schema-migrations (`pant_type`, `priority`, `mortgage_snapshots`) | Kristian | 1 |
| Backfill `pant_type` fra eksisterende mortgages | Kristian | 0.5 |
| Route-rebrand `monitoring/*` → top-level + check-batch endpoint | Kristian | 1 |
| `MonitoringService::checkMortgageDelta` + daily snapshot-job | Kristian | 3 |
| Validator-udvidelse (alert_types + person watch_type) | Kristian | 0.5 |
| Tests + smoke-test mod metis-package | Kristian | 1 |
| Endpoint-alias `debt-search` → `mortgages/search` med owner_type+debt_type | Kristian | 1 |
| **Sprint review:** Rasmus pilot-token-test | Frederik | 0.5 |

**Risiko:** Daily snapshot-job kan kræve schema-tweak hvis volume er højt. Rollback-plan: feature-flag `mortgage_delta_enabled`.

### Sprint 1 (uge 2) — F2-paritet
**Mål:** Rasmus kan eksportere kreditor-prospects som CSV.

| Task | Owner | Dage |
|---|---|---|
| CSV-eksport endpoint + S3 signed URL | Kristian | 2 |
| `DebtSearch.php` UI: "Eksporter CSV"-knap + flow | Kristian | 1 |
| owner_type filter end-to-end | Kristian | 1 |
| debt_type filter end-to-end | Kristian | 1 |
| **Sprint review:** Rasmus eksporterer + giver feedback | Frederik | 0.5 |

### Sprint 2 (uge 3-4) — F1+F5 dybde
**Mål:** Daglig email-digest LIVE. Person-watch fungerer.

| Task | Owner | Dage |
|---|---|---|
| Email-digest service (cron + Mailable + opt-in) | Jens | 3 |
| Alert-detalje-side med "før vs nu"-diff | Jens | 2 |
| Person-watch_type backend (`checkPerson`) | Kristian | 2 |
| FollowButton support for person-typen | Jens | 1 |
| Trigger-typer for selskab: `new_filing`, `name_change`, `role_change` | Kristian | 2 |
| **Sprint review:** Rasmus modtager 3 dage's emails | Frederik | 0.5 |

### Sprint 3 (uge 5-7) — P1 paritet
**Mål:** Visuel paritet på 5 superbruger-flows.

| Task | Owner | Dage |
|---|---|---|
| F3 selskabsforside med 5 widgets | Jens | 5 |
| F-NEW Portfolio-Tinglysning-tab | Kristian | 4 |
| F10 ejendomsdetalje LTV-view | Jens | 2 |
| F6 lignende handler-modul | Kristian | 4 |

### Sprint 4 (uge 8-9) — UX kvalitet
**Mål:** Differentiator-features synlige, ikke kun paritet.

| Task | Owner | Dage |
|---|---|---|
| F9 hierarkisk ejer-vis | Jens | 5 |
| F8 kilde-mærkning badge-komponent | Jens | 2 |
| F-NEW3 Lister + følg-kategorier | Kristian | 3 |
| F-NEW2 Hjem-dashboard med widgets | Kristian | 6 |

### Sprint 5 (uge 10-12) — Pilot lock-in
**Mål:** 3 kreditor-piloter signed. Case-publikation.

| Task | Owner | Dage |
|---|---|---|
| Outreach Mastercard / Nykredit / 1 advokat | Frederik | løbende |
| Onboarding-script for kreditor-pilot | Frederik | 2 |
| Case-historie Draupnir → write-up | Frederik | 3 |
| Pricing-page på metis.frankston.io | Jens | 2 |

---

## 6. Risiko-register

| Risiko | Sandsynlighed | Impact | Mitigering |
|---|---|---|---|
| Resight tilføjer tinglysning-delta før vi går LIVE | Medium | Høj | Sprint 0 prioritet, accelerér til 5 dage hvis muligt |
| Tinglysningsretten IP-whitelisting forsinkes | Medium | Medium | Følg-op ugentligt; backup: vores eksisterende crawl er prod-OK |
| Metis-package ↔ registry-api version-skew under sprint 0 | Høj | Medium | Feature-flag `mortgage_delta_v1`, alias `monitoring/*` 1 uge |
| Pilot-feedback fra Rasmus afslører UX-debt | Høj | Medium | Sprint 1 dedikerer 0.5 dag til Rasmus-feedback-iteration |
| Person-watching GDPR-blocker | Lav | Høj | Konsulter Frankston-jurist før Sprint 2; backup: kun CVR-roller, ikke CPR |
| Email-digest spam-filtres | Medium | Lav | SES whitelisting + DMARC opsætning (allerede prod) |
| Daily snapshot-job DB-load | Lav | Medium | Index på `(property_id, snapshot_date)` + partitionér tabel pr. måned |
| ReData kopierer F1 hurtigt efter vores LIVE | Medium | Lav | De har gået for tidligt ud (T:802), bias-mur stopper dem |
| Skat/eSkat-integration er forsinket | Medium | Lav | Det er bonus-edge, ikke kritisk for kerne-positionering |

---

## 7. Open questions (skal besvares før Sprint 0 starter)

1. **Backend-rebrand alias eller renamed?** Anbefaling: renamed med 1 uges alias-overgangsperiode, da `monitoring/*` ikke har eksterne konsumenter.

2. **F1 alert-granularitet?** Anbefaling: 5 typer (mortgage_change, new_lien, creditor_change, principal_change, ownership_change). UI filtrerer.

3. **Person-watch GDPR.** Skal afklares med jurist FØR Sprint 2 starter. Backup: kun CVR-roller, ikke CPR-direkte.

4. **F2 owner_type datakilde.** Verificér om `properties.primary_owner` eller `property_owners`-join. 

5. **Lejeniveau-strategi.** Bekræft: vi bygger IKKE 135K observationer. Vi bruger Boliga + kilde-mærkning + kreditor-fokus.

6. **Pricing model.** Anbefaling: Standard tier 25K DKK/bruger/år (under ReData Premium 273K, over Resight basis 15K), med rabat på volumen. Plus Creditor-pakke add-on (AVM + credit + AIS) 25K oveni.

7. **Metis-master integration.** Skal embedded mode opdateres parallelt med standalone? Anbefaling: ja, men separat sprint efter pilot-validering.

---

## 8. Konfidens-scores (per CLAUDE.md confidence-check)

| Sektion | Score | Begrundelse |
|---|---|---|
| Strategisk positionering "Kreditorernes Resight" | 88 | Stærkt grounded i transcript + data-edges, men ikke validated med ekstern kreditor endnu |
| F1.0 mortgage_change-arkitektur | 92 | Grounded i faktisk schema + MonitoringService-pattern. Snapshot-volume-risiko er kendt. |
| F1.1 backend rebrand | 95 | Triviel, kun politisk risiko hvis nogen konsumenter findes vi ikke har set |
| F2.1 owner_type/debt_type | 78 | `pant_type` backfill-heuristik er ikke testet. Kan kræve manuel klassifikation for ~20% af rows. |
| F3 selskabsforside | 85 | Datagrundlag er der; UI-arbejde er primært. Pie chart kræver BBR usage_code-aggregation. |
| F-NEW Portfolio-Tinglysning | 90 | Killer view. Datagrundlag er klar. UI er ligetil. |
| F6 lignende handler | 82 | Similarity-query design er ikke benchmarked på performance |
| F9 hierarkisk ejer-vis | 75 | UX-design afhænger af hvor dyb ejer-strukturen kan være. Performance på 200+ noder. |
| F-NEW2 Hjem-widgets | 70 | Nice-to-have, mange detaljer åbne |
| Sprint-rækkefølge realisme | 73 | 12 uger er realistic, men kræver Kristian + Jens i fuld kapacitet — Verdi-konflikt med Jens på ~80% er reel |
| Pilot-acquisition i sprint 5 | 65 | Outreach-arbejde er ikke fully scoped, kreditor-segmentet er ikke pre-warmed |

**Samlet confidence:** ~82 — solid foundation, men 2-3 åbne strategiske valg + Verdi-kapacitet-spørgsmål skal besluttes før Sprint 0.

---

## 9. Hvad gør Metis bedre end Resight efter denne sprint-cyklus?

| Feature | Resight | Metis efter 12 uger |
|---|---|---|
| Tinglysning-delta-alerts | ❌ | ✅ |
| Omvendt gælds-søgning med eksport | ❌ | ✅ |
| AVM/credit-scoring/stress-test | ❌ | ✅ (eksisterende, brand "Creditor pakke") |
| Open Banking-integration | ❌ | 🟡 (når sandbox lander) |
| eSkat låne-data | ❌ | 🟡 (kreditformidler-vej fra 1. juli) |
| Hierarkisk ejer-vis (alt. Resights spider) | Spider | ✅ Toggle hierarki/spider |
| Kilde-mærkning på leje-/AVM-data | Skjult antagelse | ✅ Transparent |
| Portfolio-Tinglysning-tab på selskab | ❌ | ✅ |
| Daglig email-digest | ✅ | ✅ paritet |
| Lister + kategorier | ✅ | ✅ paritet |
| Hjem-dashboard | ✅ | 🟡 (sprint 4) |
| 135K leje-observationer | ✅ | ❌ (bevidst skip) |
| Byggeri/udbud | ✅ | ❌ (bevidst skip) |
| 600+ map layers | ✅ | 30 (kreditor-relevante) |
| Kreditor-personaliseret | ❌ | ✅ ENESTE |
| Open API til kunder | ❌ | ✅ (registry-api) |

**Kort:** Vi taber bevidst på 3 felter (lejedata, byggeri, map-bredde). Vi vinder klart på 6+ felter. Vi har paritet på resten.

---

## 10. Næste skridt efter Frederik godkender designet

1. Frederik review + feedback (i morgen)
2. Spec → `/plan` skill → detaljeret implementeringsplan med per-task tasks
3. Sprint 0 kickoff: backend-rebrand pull request første dag
4. Pilot-token til Rasmus med `mortgage_change` alert-type fra dag 7
5. Compound entry når sprint 0 er done — capture læringerne om route-rebrand vs alias

**Ende på design-dokument (v1).**

---

## Addendum: Review-feedback (v1.1, 1. maj 2026)

Tre uafhængige review-agenter analyserede spec v1. Deres samlede feedback (detaljer i `docs/user-research/2026-04-29-draupnir-resight/morning-handoff.md` sektion "Review-agent fund") gav følgende rettelser til spec v1.1:

### A. Scope-cuts (frigør 15-20 dage)

- **Cut F-NEW2 Hjem-dashboard** fra 12-uger-scope. Visuel paritet driver ikke retention; Rasmus kommer ind via /alerts eller direkte søgning.
- **Cut F8 kilde-mærkning** fra 12-uger-scope. Marketing-spil; ingen kreditor har efterspurgt det.
- **Defer F9 hierarkisk ejer-vis** til post-pilot. Resight-spider er stadig fallback.
- **Defer F-NEW3 Lister** indtil Rasmus eksplicit beder om det.
- **Trim F3 selskabsforside** fra 5 widgets til 3 (cut map med pins + pie chart).

### B. Sprint-plan revision

Sprint 0 splittes til **0a + 0b** for at undgå over-loading:

| Sprint | Uger | Outcome |
|---|---|---|
| **0a. Backend-foundation** | uge 1 | Route-rebrand + validators + audit alle Frankston repos for monitoring/* consumers |
| **0b. Mortgage-delta engine** | uge 2 | checkMortgageDelta + snapshot+events hybrid + tests + smoke-test |
| **1. F2-paritet** | uge 3 | owner_type + debt_type + CSV-eksport |
| **2. F1+F5 dybde** | uge 4-5 | Email-digest (Horizon queue + Rappasoft pattern), person-watch_type, alert-detalje-side |
| **3. P1 paritet** | uge 6-8 | F3 trimmed (3 widgets), F-NEW Portfolio-Tinglysning, F10 LTV, F6 lignende handler |
| **4. Rasmus-iteration** | uge 9-10 | Pilot-feedback indsamling + UX-tweaks + buffer |
| **5. Pilot lock-in** | uge 11-12 | 2-3 ekstra kreditor-piloter, case-historie |

Reduktion: 12 uger → realistisk 10 uger plus større buffer.

### C. Arkitektur-rettelser

1. **Backend-rebrand**: Drop 1-uges-alias. **Bump til `/v2/*` eller brug Sunset/Deprecation HTTP headers (RFC 8594/9745) med 30-dages audit.** Sprint 0a dag 1: `grep -r "v1/monitoring" Frankston-master/ Trust-platform/ faktorkredit/` på alle Frankston repos før delete.

2. **Mortgage-delta engine: hybrid snapshot+events**:
   - Behold `mortgage_snapshots` table (current state cache for hurtig diff)
   - Tilføj `row_hash` kolonne (sha256 af significant cols) for 100x hurtigere diff
   - Idempotency: `(property_id, snapshot_date)` UNIQUE + `ON CONFLICT DO NOTHING`
   - Missed-day recovery: diff vs `last_available_snapshot`, ikke `today - 1`
   - Partition `mortgage_snapshots` by month (Postgres native)
   - **Tilføj `mortgage_events` table** parallelt (event-log appended ved hver detected delta) — løser audit + historic-backfill use cases architecture-reviewer rejste

3. **F-NEW Portfolio-Tinglysning backend-first**: definér `GET /v1/companies/{cvr}/portfolio-mortgages?per_page=50&sort=principal_desc` med aggregate-envelope FØR UI. Server-side paginering fra row 1, ikke ved >100. `POST /v1/watchlists/bulk` for "Følg alle 18"-knap.

4. **Email-digest pattern**:
   - Hourly cron `digests:send-due` (Rappasoft pattern) — per-user local-hour match (default Europe/Copenhagen / 08:00)
   - Per-user `BuildDigestJob` på Horizon `digests`-queue
   - `digest_runs` table med UNIQUE `(user_id, digest_date)` for idempotency
   - SchedulerFailureNotifier convention (memory: project_scheduled_command_monitoring.md)
   - Empty-state gate før queue-dispatch
   - Brug Laravel Notifications + ShouldQueue, ikke raw Mailable

5. **Embedded-vs-standalone abstraction**: Long-term post-Sprint-5 — ekstrahér `MetisRegistryClient`-interface i package med `RegistryApiAdapter` + `FrankstonApiAdapter`-implementeringer. Eliminerer duplicerede Livewire-komponenter i Frankston-master.

### D. Alert-type granularitet

Reduktion fra 5 typer til 3:
- `new_lien` (udlæg / retsanmærkning — high priority)
- `mortgage_change` (alle øvrige pant-events: ny, fjernet, principal-change, creditor-change — beskrives i description-felt)
- `ownership_change` (ejer-skift — eksisterende)

Saves validator-kompleksitet, UI-filter-states, email-template-branches.

### E. Konfidens-justering efter review

Samlet spec-confidence stiger fra 82 til **88** efter review-input. De resterende 12 point afventer:
- Verifikation af owner_type datakilde (Sprint 0a dag 2)
- Audit af monitoring/* consumers (Sprint 0a dag 1)
- Pilot-acquisition realisme (afhænger af outreach-arbejde fra Frederik)
- GDPR-clearance på person-watching (Sprint 2 blocker)

---

**Ende på design-dokument (v1.1, post-review).**
