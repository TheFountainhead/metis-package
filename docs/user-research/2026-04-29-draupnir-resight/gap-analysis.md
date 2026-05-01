# Gap-analysis: Metis v2.6.0 + 19 PRs vs. Draupnir-feedback

**Status pr. 1. maj 2026.** Verificeret mod live `metis.frankston.io` + kode i `metis-package/src/` + `registry-api/app/` + 19 mergede PRs siden Draupnir-mødet 29. apr.

**Detaljer:** se `phase-A-findings.md` for `verified-vs-assumed`-tabel og kilde-citationer.

**Legend:**
- ✅ Done — feature lever i v2.6.0+ og matcher Resight-paritet
- 🟡 UI bygget, ikke end-to-end (route-mismatch eller backend-mangel)
- 🟦 Foundation lagt, kerne mangler
- ❌ Missing — skal bygges
- ⏭️ Skip — bevidst ikke prioriteret

---

## P0 — Sticky differentiators (BLOCKERS for Rasmus-skifte)

### F1: Alerts på gælds-ændringer pr. fulgt ejendom — 🟦

**Hvad findes nu:**
- ✅ FollowButton.php (`metis-package/src/Livewire/FollowButton.php:1-88`)
- ✅ AlertsInbox.php (`metis-package/src/Livewire/AlertsInbox.php:1-143`)
- ✅ /alerts route med pilot-token-gate (PR #6)
- ✅ Watchlist + Alert models (`registry-api/app/Models/`)
- ✅ MonitoringService scaffold (`registry-api/app/Services/Monitoring/MonitoringService.php`)
- ✅ Cron-cmd `RunMonitoringCommand`
- ✅ `transaction` (handelsdata) + `ownership_change` (indirekte) detektorer
- ✅ Resight-style left sidebar (PR #10)

**Hvad mangler:**
- [ ] **`mortgage_change` detektor (KERNE-FEATURE)** — daglig delta på `mortgages`-tabel pr. fulgt BFE: nye, fjernede, ændret hovedstol, ændret rente, ændret kreditor
- [ ] Differentiation `new_lien` (udlæg = high priority) vs `new_mortgage` (ejerpantebrev = medium priority)
- [ ] Email-notifikation (kun in-app alerts p.t.)
- [ ] Detalje-side med "før vs. nu" diff (visuel diff på pantebrev-listen)
- [ ] Route-aliaser: `/v1/watchlists` → `/v1/monitoring/watchlists` osv.
- [ ] `check-batch` endpoint (eksisterer slet ikke i registry-api)
- [ ] Alert_types validator-udvidelse: `mortgage_change`, `new_lien`, `creditor_change`, `principal_change`

**Datagrundlag-status:**
- Tinglysning wrapped i Registry-API: ✅
- Tidsserier/snapshots på tinglysning: 🟡 (mortgages-tabel har is_active flag, men ingen explicit snapshot-tabel for delta-beregning)
- Tinglysning crawl-frekvens: dagligt med backoff (commit `957886d`)

**Estimat:** 4-7 dage for backend (delta-engine + endpoint-aliaser + alert-types + email). 1-2 dage for UI-detail-page diff. **Total: ~7-10 dage til reel pilot-værdi.**

**Blockers:**
- Schema-mismatch metis ↔ registry-api skal løses før pilot kan virke.
- Mangler GDPR-vurdering for person-watching (relateret til F5).

**Source:** T:497-518, T:347 (Rasmus' egen "autopilot"-forespørgsel)

---

### F2: Omvendt søgning på gæld — 🟡

**Hvad findes nu:**
- ✅ DebtSearch.php (`metis-package/src/Livewire/DebtSearch.php:1-237`) — full filter-UI
- ✅ MortgageSearchController.search (`registry-api/app/Http/Controllers/Api/V1/MortgageSearchController.php:15-90`)
- ✅ Filtre: creditor (ilike), min/max amount, min/max rate, postal_codes (array), postal_code_from/to
- ✅ Active mortgage flag, ordering by principal_amount DESC
- ✅ "Off. vurdering / Seneste handel / Tinglyst gæld" kolonner i resultatlisten (PR #19)
- ✅ Cursor-paginering (HMAC-signed) med klient-side back-stack

**Hvad mangler:**
- [ ] Route-alias `/v1/debt-search` → `/v1/mortgages/search` (eller migrer metis-package)
- [ ] **`owner_type` filter (selskab vs. privatperson)** — kræver join med property_owners + ejer-type-klassifikation
- [ ] **`debt_type` filter (ejerpantebrev / realkredit / privat pantebrev)** — kræver klassifikations-felt på `mortgages` (er det allerede der? grep `pantebrev_type`)
- [ ] **CSV-eksport endpoint** (`POST /v1/debt-search/export-link`) — endpoint findes ikke i registry-api
- [ ] Gem-søgning + alert-når-ny-match (overlap med F1's alert-engine)

**Datagrundlag-status:**
- Coverage: ~33% af ejendomsmasse pr. F's demo (T:255-256). Skal verificeres mod live `mortgages`-tabel.
- Aktuel coverage tjekkes: `SELECT COUNT(DISTINCT property_id) FROM mortgages WHERE is_active = true` mod total `properties` count.

**Estimat:** 3-5 dage til feature-paritet (route-alias + 2 filtre + CSV).

**Blockers:**
- `owner_type` kræver afklaring: hvor er ejer-type-flag? Er det `property_owners.owner_type`? Eller skal vi udlede fra CVR-relation?

**Source:** T:20, T:254-268, T:347

---

## P1 — Resight-paritet (kvalitets-vinkler)

### F3: Selskabsforside med portefølje-overblik — 🟦

**Status:** Foundation findes (CVR-opslag, regnskaber, roller, ejerstruktur, ejendomme), men "Resight-style overview"-screenshot 5 har:
- Hovedboks med navn, CVR, branche, status, antal ansatte
- Sidebar med nøgletal (omsætning/balance/EK 3-årig)
- Pie chart med portefølje-fordeling pr. anvendelses-type
- Lille kort med pin pr. ejendom
- Direct-links til regnskab/ejendomsliste/tinglysning/historik

**Tjekliste:**
- [ ] Nøglepersoner-boks (bestyrelse + direktion summary, ikke kun roller-liste) — verificér i Fase B1
- [ ] Nøgletal-boks med 3-årig graf — ❌ ikke implementeret
- [ ] Portefølje-fordeling pie chart — ❌ ikke implementeret
- [ ] Mini-kort med ejendoms-pins — ❌ ikke implementeret (vi har kort på adresse-side, ikke selskabs-forside)
- [ ] Quick-action links — 🟡 partial via /lookup/cvr-side; ikke i Resight-overblik-stil

**Estimat:** 3-5 dage. Genbrug `Sections/`-pattern fra adresse-lookup.

**Source:** T:370-378, screenshots 01 + 05

---

### F4: Regnskab som direkte PDF-download — ✅ med caveat

**Status:** PDF-source-detection bygget i v2.6.0 (memory bekræfter). Edge-case: store designede regnskaber kan både Resight og Metis fejle — kan vi bygge fallback til Erhvervsstyrelsen direkte API?

**Tjekliste:**
- [✅] En-klik download fra selskabsside
- [✅] PDF source detection (struktureret vs. designet)
- [ ] Fallback til CVR-kald hvis lokal cache mangler (verificér i Fase B1)
- [ ] Edge-case håndtering for "designet PDF" — markedsmessig fordel (T:380-388)

**Source:** T:380-388

---

### F5: Følg-funktion (selskab + ejendom + person) — 🟡

**Status:** UI er generisk (FollowButton tager watchType + watchValue), backend understøtter `property | postal_code | company`. Person mangler.

**Tjekliste:**
- [✅] Følg-knap som komponent
- [✅] Personlig følg-liste på /alerts (men kræver token)
- [ ] **Person-watch_type på backend** (`MonitoringController.php:19` validator-udvidelse + ny `checkPerson` metode)
- [ ] Email-digest (daglig/ugentlig)
- [ ] Trigger-typer for selskab: `new_filing` (regnskab landed), `name_change`, `role_change` — `MonitoringService::checkCompany` har kun `ownership_change`
- [ ] Trigger-types for ejendom: F1 `mortgage_change` (overlap med F1)

**Estimat:** 3-5 dage. Person-watching er det største stykke (kræver CPR-data + GDPR-vurdering).

**Source:** T:489-491

---

### F6: Ejendomsdetalje med "lignende handler" + skråfoto — 🟦

**Tjekliste:**
- [ ] **Tabel med 5-10 lignende handler** (kvm-pris, dato, areal, anvendelses-type) — ikke fundet i src/
- [ ] **Skråfoto** (Datafordeleren WMS / SDFE / Google Static Maps oblique?) — kræver datakilde-research
- [ ] Matrikelkort side-om-side med skråfoto (ikke kun adresse-pin) — verificér

**Datakilder vi kan bruge:**
- Lignende handler: PropertyTransaction-model + similarity-query (samme postnummer, ±20% areal, samme anvendelses-type, sidste 24 mdr)
- Skråfoto: SDFE Skråfoto WMS (offentlig), eller Google Static Maps `tilt=45` parametre (commercial)

**Estimat:** 4-6 dage (3 dage similar-trades, 2-3 dage skråfoto-integration)

**Source:** T:443-446, screenshot 03

---

### F7: Person-søgning med ejer-roller + ejendomsmasse — ✅

**Status:** Memory bekræfter person-søgning bygget med roller + selskaber + ejendomsantal (147 for Bisgaard-Frantzen). PR #15 unified person-property portfolio view.

**Tjekliste:**
- [✅] Slå person op
- [✅] Liste over CVR-roller
- [✅] Liste over ejede selskaber
- [✅] Liste over ejendomme (direkte + indirekte via selskaber)
- [ ] Person-FOLLOW (overlap med F5) — ikke muligt p.t.

**Source:** T:418

---

## P2 — Bedre end Resight (kvalitets-vinkler)

### F8: Lejeniveau med kilde-mærkning — ❌

**Tjekliste:**
- [ ] Datakilde for lejeniveau — vi har ingen i v2.6.0 (Resight har det delvist annonce-antaget)
- [ ] Datakilde-kandidater: Boliga annoncer (allerede i registry-api), GLR (Grundejernes Investeringsfond hvis adgang), manuel indberetning fra kunder
- [ ] Badge pr. data-punkt: "Indberettet" / "Annonce-baseret" / "Modelleret"
- [ ] Tooltip forklarer kilde-typen
- [ ] Filter til kun verificerede data

**Estimat:** Stor — kræver data-strategi før implementation. 10-15 dage for MVP med Boliga annonce-data + kilde-badge. Skal også overveje legal/compliance.

**Source:** T:272-296

---

### F9: Hierarkisk ejer-visualisering — ❌

**Tjekliste:**
- [ ] Træ-visning med ekspander/kollaps
- [ ] Default visning kun primær gren (mor → datter → barnebarn for søgte node)
- [ ] Toggle mellem spider (Resight-stil) og hierarki (Metis-stil)
- [ ] Visuel kontrast: primært ejerforhold (>50%) fed, indirekte tynd

**Datagrundlag:** Vi har allerede ejerstruktur-data via `fetchCompanyStructure(cvr)` (`RegistryApi.php:238`). Mangler kun visualiserings-komponent.

**Estimat:** 3-4 dage (Livewire + Alpine for ekspander/kollaps + Leaflet/d3 hvis vi vil have grafisk)

**Source:** T:392-410

---

### F10: Vurdering + tinglyst hovedstol side-om-side — 🟡

**Status:** PR #19 implementerede dette som **portefølje-tabel-kolonner** (Off. vurdering | Seneste handel | Tinglyst gæld). Men ejendomsdetalje-view (når man klikker ind på én ejendom) mangler stadig:

**Tjekliste:**
- [✅] Portefølje-tabel kolonner (PR #19)
- [ ] **Ejendomsdetalje-view** med "Vurdering 2024 | Tinglyst hovedstol | LTV-indikator (low/medium/high)"
- [ ] Disclaimer "Tinglyst hovedstol ≠ aktuel restgæld" (T:329)
- [ ] LTV-indikator-farver (grøn <60%, gul 60-80%, rød >80%)

**Estimat:** 1-2 dage (data findes, UI-arbejde)

**Source:** T:317-329

---

### F11: Køber-profil-analyse (ejerskifte → demografi) — ❌

**Status:** Prototype eksisterede iflg. F (T:446) men ikke produktiseret. B2B-vinkel mod ejendomsmæglere — IKKE prioriteret for Draupnir/kreditor-segment.

**Estimat:** Kan parkeres til senere (ikke kritisk for Rasmus' skifte)

**Source:** T:446

---

## P3 — Skip / lav prioritet

### F12: Bygge-modul (offentlige udbud) — ⏭️
Rasmus tester det ikke værd (T:467-485). Skip.

### F13: AI-assistent — ⏭️
Resights AI bruger Rasmus ikke ("aldrig noget brugbart" T:539). Lav forventning. **Men:** Vi kunne bygge en simpel AI-search ovenpå Metis-data der bare ER bedre end Resights — fordi standarden er meget lav. Senere prioritet.

### F14: Pris som primær differentiator — ⏭️
Moat er sticky-features, ikke pris. Skip som standalone strategi (men 25% rabat aftalt med Rasmus).

---

## Sammenfatning

**Total status:**
- P0 done: 0 / 2 (begge er 🟦/🟡 — UI klar, backend ikke færdigt)
- P1 done: 1 / 5 fuldt (F4) + 2 partial (F3 🟦, F5 🟡, F6 🟦, F7 ✅)
- P2 done: 0 / 4 (F10 🟡 partial)
- P3: 3 skip ✓

**Kritiske blockers før pilot kan fungere ende-til-ende:**
1. Route-alias `/v1/watchlists`, `/v1/alerts`, `/v1/debt-search` (eller migrér metis-package)
2. `check-batch` endpoint på registry-api
3. `mortgage_change` alert-type + `MonitoringService::checkMortgageDelta()` implementering
4. `owner_type` + `debt_type` filtre i debt-search
5. Email-digest service (mangler nodvendigvis ikke for pilot, men højt på roadmap)

**Næste konkrete skridt (sprint-prioritet):**
1. **Backend-rebrand sprint (3-5 dage):** registry-api route-aliaser + check-batch + person watch_type + alert_types-udvidelse + checkMortgageDelta. Dette låser F1+F2 ende-til-ende.
2. **F2-paritet (2-3 dage):** owner_type, debt_type, CSV-eksport
3. **Pilot-validering med Rasmus (1 uge):** Få ham til at teste F1+F2 end-to-end, indsamle feedback før vi går videre
4. **F3 selskabsforside (3-5 dage)** + **F10 ejendomsdetalje-LTV (1-2 dage)** parallelt
5. **F6 lignende handler + F9 hierarkisk ejer-vis (3-7 dage tilsammen)**

**Estimat til "Rasmus klar til skifte":**
- Best case: 3-4 uger (hvis backend-sprint kører rent + Rasmus' pilot-feedback ikke trigger større omarbejdning)
- Realistisk: 6-8 uger (med pilot-iteration + UX-polering)
- Worst case: 10-12 uger (hvis F8 lejeniveau viser sig at være bar-stiver)

**Open questions tilbage at afklare:**
1. Backend-rebrand-strategi (alias eller migration?)
2. F1 alert_types granularitet (ÉN type vs flere)
3. F5 person-watch GDPR
4. F2 owner_type datakilde
5. F8 lejeniveau-strategi (bygge eller licensere?)
6. F11 køber-profil — parkeres eller bygges som B2B-side-spil?
