# Gap-analysis: Metis vs. Draupnir-feedback

**Status pr. 9. juli 2026.** Verificeret mod live `metis.frankston.io` + kode i `metis-package/src/` + `registry-api/app/` + **prod-DB read-only inspektion** (Forge command-dispatch).

**Historik:**
- 1. maj 2026: Initial gap-analyse efter 19 PRs siden Draupnir-mødet 29. apr (se git-history)
- 4. maj 2026: Opdateret efter ~25 nye PRs der adresserede flere af gap-items direkte
- 9. juli 2026: Opdateret efter PR #49-58 (AlertDetail diff-view, similar trades, F3 CompanyOverview, F5 modal, F2 filtre, energimærke, observer-path alerts). **Begge P0-blockers verificeret LUKKET i prod.**

**Detaljer:** se `phase-A-findings.md` for `verified-vs-assumed`-tabel og kilde-citationer.

**Legend:**
- ✅ Done — feature lever i prod og matcher Resight-paritet
- ✅+ Bedre end Resight — vi har en kvalitets- eller funktionel-vinkel der overgår dem
- 🟡 UI bygget, ikke end-to-end (route-mismatch eller backend-mangel)
- 🟦 Foundation lagt, kerne mangler
- ❌ Missing — skal bygges
- ⏭️ Skip — bevidst ikke prioriteret

---

## P0 — Sticky differentiators (BLOCKERS for Rasmus-skifte)

### F1: Alerts på gælds-ændringer pr. fulgt ejendom — ✅ LIVE (prod-verificeret 9/7)

**Prod-verifikation 9. juli 2026 (read-only DB-inspektion):**
- Arkitektur: event-drevet — `MortgageObserver` dispatcher `DetectMortgageChange` ved hver mortgage-write (real-time, ingen daglig snapshot-diff nødvendig)
- `mortgage:digest:send` kører dagligt 08:00 UTC → `MortgageDigestMail` pr. bruger
- **Rasmus (user 2) modtager digests i praksis:** "Nyt ejerpantebrev: Vestergade 58" (29/6, digest 30/6 08:00) og "Nyt udlæg: Høbjergvej 29A+29B" (3/7, digest samme morgen 08:00:04)
- Udlæg vs. ejerpantebrev differentieres i alert-titler ✓
- Rasmus har 31 aktive watches (7 property + 24 company) og 37 alerts totalt
- Nul u-digestede mortgage-alerts (backlog tom); scheduler-liveness bekræftet dags dato
- AlertDetail med før/nu-diff (PR #49), tinglyst dato + udløbsdato (PR #50), rente i facts-panel + observer-path rendering (PR #58)
- `new_transaction`-alerts (29 stk) er bevidst in-app-only — ikke i mail-digest

**Tilbageværende (ikke-blokerende):**
- [ ] `check-batch` endpoint (aldrig bygget — uklart om stadig relevant)

<details><summary>Historisk status pr. 4. maj (🟦)</summary>

**Hvad findes nu:**
- ✅ FollowButton.php (`metis-package/src/Livewire/FollowButton.php:1-88`)
- ✅ AlertsInbox.php (`metis-package/src/Livewire/AlertsInbox.php:1-143`)
- ✅ /alerts route med pilot-token-gate (PR #6)
- ✅ Watchlist + Alert models (`registry-api/app/Models/`)
- ✅ MonitoringService scaffold (`registry-api/app/Services/Monitoring/MonitoringService.php`)
- ✅ Cron-cmd `RunMonitoringCommand`
- ✅ `transaction` (handelsdata) + `ownership_change` (indirekte) detektorer
- ✅ Resight-style left sidebar (PR #10)

**Hvad mangler (uændret siden 1. maj):**
- [ ] **`mortgage_change` detektor (KERNE-FEATURE)** — daglig delta på `mortgages`-tabel pr. fulgt BFE: nye, fjernede, ændret hovedstol, ændret rente, ændret kreditor
- [ ] Differentiation `new_lien` (udlæg = high priority) vs `new_mortgage` (ejerpantebrev = medium priority)
- [ ] Email-notifikation (kun in-app alerts p.t.)
- [ ] Detalje-side med "før vs. nu" diff (visuel diff på pantebrev-listen)
- [ ] Route-aliaser: `/v1/watchlists` → `/v1/monitoring/watchlists` osv.
- [ ] `check-batch` endpoint (eksisterer slet ikke i registry-api)
- [ ] Alert_types validator-udvidelse: `mortgage_change`, `new_lien`, `creditor_change`, `principal_change`

**Estimat:** 7-10 dage til reel pilot-værdi.

**KRITISK:** Dette er Rasmus' egen eksplicitte "primære sticky mulighed" — citat fra resumé side 6: *"Det er Frankstons primære 'sticky' mulighed."* Skal være første prioritet efter dagens UX-runde.

**Source:** T:497-518, T:347 (Rasmus' egen "autopilot"-forespørgsel), resumé side 6+11

</details>

---

### F2: Omvendt søgning på gæld — ✅ (kode-verificeret 9/7)

**Alle 4. maj-mangler er lukket:**
- [✅] Route `/v1/debt-search` (routes/api/v1.php:74) m. quota + throttle
- [✅] `owner_type` filter (company/person) — backend-valideret
- [✅] `debt_types` filter (realkredit/ejerpantebrev/privat/udlæg/arrest/skadesløsbrev/indeks/anden)
- [✅] CSV-eksport: `POST /v1/debt-search/export-link` + `GET /v1/debt-search.csv` + download-dispatch i DebtSearch.php
- [✅] Gem-søgning/alert-ved-match dækket via watchlist-typerne (postal_code-watch findes i prod)
- [✅] Dato-filter + Hovedstol-formatering fra Rasmus-feedback (PR #56)

<details><summary>Historisk status pr. 4. maj (🟡)</summary>

**Hvad findes nu:**
- ✅ DebtSearch.php (`metis-package/src/Livewire/DebtSearch.php`)
- ✅ MortgageSearchController.search
- ✅ Filtre: creditor (ilike), min/max amount, min/max rate, postal_codes
- ✅ Off. vurdering / Seneste handel / Tinglyst gæld kolonner
- ✅ Cursor-paginering
- ✅ Beta-demo gennemført med Rasmus

**Hvad mangler (uændret):**
- [ ] Route-alias `/v1/debt-search` → `/v1/mortgages/search`
- [ ] **`owner_type` filter (selskab vs. privatperson)**
- [ ] **`debt_type` filter (ejerpantebrev / realkredit / privat pantebrev)**
- [ ] **CSV-eksport endpoint**
- [ ] Gem-søgning + alert-når-ny-match (overlap med F1)

**Estimat:** 3-5 dage til feature-paritet

**Source:** T:20, T:254-268, T:347

</details>

---

## P1 — Resight-paritet (kvalitets-vinkler)

### F3: Selskabsforside med portefølje-overblik — ✅ (PR #53, 4. maj)

CompanyOverview-section med kort + grafer landet i PR #53. Nøglepersoner/roller fandtes i forvejen (CompanyRoles).

<details><summary>Historisk status pr. 4. maj (🟦)</summary>

**Tjekliste:**
- [✅] Nøglepersoner/roller-liste (CompanyRoles section)
- [ ] Nøgletal-boks med 3-årig graf — ❌ ikke implementeret
- [ ] Portefølje-fordeling pie chart (helårsbeboelse / hotel / øvrige) — ❌ ikke implementeret
- [ ] Mini-kort med ejendoms-pins på selskabsforsiden — ❌ ikke implementeret
- [ ] Quick-action links — 🟡 partial via section-headers

**Estimat:** 3-5 dage. Genbrug `Sections/`-pattern.

**Source:** T:370-378, screenshots 01 + 05 (Figur 2+3 i PDF-resumé)

</details>

---

### F4: Regnskab som direkte PDF-download — ✅ med caveat

**Status:** PDF-source-detection bygget. Edge-case: store designede regnskaber kan både Resight og Metis fejle.

- [✅] En-klik download fra selskabsside
- [✅] PDF source detection (struktureret vs. designet)
- [ ] Fallback til CVR-kald hvis lokal cache mangler
- [ ] Edge-case håndtering for "designet PDF"

**Source:** T:380-388

---

### F5: Følg-funktion (selskab + ejendom + person) — ✅ (opdateret 9/7)

**Tjekliste:**
- [✅] Følg-knap som komponent
- [✅] Personlig følg-liste på /alerts (men kræver token)
- [✅] **Person-watch backend bygget** — `person-roles:snapshot` (03:30 UTC), monitoring-diff (04:00), `PersonDigestMail` (08:30) + GDPR-retention: 24-mdr alert-prune (DPIA mitigation R5) og snapshot-prune (daglig 90d / ugentlig 1å / månedlig forever)
- [✅] PersonFollowButton 2-step disambiguation modal (PR #55)
- [✅] Email-digest (daglig) — mortgage 08:00 + person 08:30 UTC
- [✅] Selskabs-watches virker i praksis (Rasmus har 24 aktive company-watches i prod)
- [✅] Ejendoms-triggers via F1 mortgage-pipeline
- [ ] Ingen aktive person-watches i prod endnu (0 brugere har taget det i brug — feature findes)

**Source:** T:489-491

---

### F6: Ejendomsdetalje med "lignende handler" + skråfoto — 🟡 (lignende handler ✅ 9/7)

**Tjekliste:**
- [✅] **Tabel med lignende handler** — AddressSimilarTrades Livewire-section (PR #52)
- [✅] Energimærke-sektion (PR #57 — bonus ud over original gap; badge-farver, Expired-badge, ENS PDF-link)
- [ ] **Skråfoto** (Datafordeleren WMS / SDFE) — kræver datakilde-research → **Spor 2, juli 2026**
- [ ] Matrikelkort side-om-side med skråfoto

**Datakilder vi kan bruge:**
- Lignende handler: `PropertyTransaction`-model + similarity-query (samme postnummer, ±20% areal, samme anvendelses-type, sidste 24 mdr)
- Skråfoto: SDFE Skråfoto WMS (offentlig), eller Google Static Maps `tilt=45`

**Estimat:** 4-6 dage (3 dage similar-trades, 2-3 dage skråfoto)

**KRITISK:** Resight viste 376 lignende handler i Svampedamvej 25-eksemplet (Figur 5 i resumé). Det er den feature Rasmus viste mest entusiasme over.

**Source:** T:443-446, screenshot 03 / Figur 5 i PDF-resumé

---

### F7: Person-søgning med ejer-roller + ejendomsmasse — ✅

**Status:** Person-søgning bygget med roller + selskaber + ejendomsantal. PR #15 unified person-property portfolio view.

- [✅] Slå person op
- [✅] Liste over CVR-roller
- [✅] Liste over ejede selskaber
- [✅] Liste over ejendomme (direkte + indirekte via selskaber)
- [ ] Person-FOLLOW (overlap med F5) — ikke muligt p.t.

**Source:** T:418

---

## P2 — Bedre end Resight (kvalitets-vinkler)

### F8: Lejeniveau med kilde-mærkning — ✅+ NYT 4. maj

**Status:** **Markant forbedret 3-4. maj 2026.** Vi har nu en reel kvalitets-vinkel over Resight (som bruger antagne tal fra forsvundne annoncer).

- [✅] Lejeestimat med eksplicit "Kilde: ejendomstorvet.dk" attribution
- [✅] Sample-count vises (X listings i postnummerområdet)
- [✅] Property-type filter (Office/Retail/Warehouse/Production/Other) inkl. legacy BBR 1.0 (200-range) koder
- [✅] Konservativt/Marked/Aggressivt scenario-toggle (×0.90 / ×1.00 / ×1.05) der genberegner Bruttoafkast + DSCR live
- [✅] **Vægtet mixed-use rent estimate** for multi-tenant ejendomme (Σ unit_area × type_median / total_area) med per-type breakdown-tabel
- [ ] Badge pr. data-punkt: "Indberettet" / "Annonce-baseret" / "Modelleret" — ❌ stadig kun annonce-baseret
- [ ] Datakilde-udvidelse: GLR (Grundejernes Investeringsfond) / manuel indberetning fra kunder — kræver business-development

**Estimat:** Resterende arbejde 5-7 dage (kilde-typer + kvalitet/coverage).

**Source:** T:272-296, screenshot 06

---

### F9: Hierarkisk ejer-visualisering — ✅+ FÆRDIG 3-4. maj

**Status:** **Komplet implementeret 3-4. maj 2026.** Rigtig differentiator over Resight's spider-graf (T:392-410).

- [✅] Hierarkisk org-chart med real divs (no pseudo-element fragility)
- [✅] **Reel ejer / Legal ejer separation** baseret på CVR's `role_label` (BeneficialOwner vs EJERREGISTER)
- [✅] EJF/Tinglysning cross-check så historiske ejere ikke forurener current view
- [✅] Multi-100% conflict resolver (drop'er stale CVR-records uden gyldigTil)
- [✅] Reklassificering af shareless owners som historiske
- [✅] Collapsible "tidligere ejere"-toggle
- [✅] **"Udfold struktur"-feature pr. ejer-card** (lazy-load via wire:click) — viser:
  - For person: alle deres aktive selskaber
  - For company: deres datterselskaber
- [✅] Drilldown-link "Se alle selskaber" / "Se selskab" på hver node
- [✅] Connectors: trunk + bridge + stems som rigtige divs (ikke pseudo-elementer — undgår flux:card overflow-clipping)

**Source:** T:392-410, mange PR'er 3-4. maj

---

### F10: Vurdering + tinglyst hovedstol side-om-side — ✅+ NYT 3. maj

**Status:** **Forbedret 3. maj 2026.**

- [✅] Portefølje-tabel kolonner (Off. vurdering | Seneste handel | Tinglyst gæld)
- [✅] **Grund / Bebygget / Etageareal split** baseret på BBR byg041BebyggetAreal (footprint) vs byg038SamletBygningsareal (etageareal sum)
- [✅] **Frasolgte ejendomme split** — EJF/Tinglysning cross-check med "X frasolgte ejendomme" toggle (sale_date + sale_price)
- [✅] Matrikel-fallback når BFE har ingen DAWA-adresse ("Ubebygget grund — Skovlunde By")
- [ ] **Ejendomsdetalje-view** med "Vurdering 2024 | Tinglyst hovedstol | LTV-indikator (low/medium/high)" — 🟡 partial
- [ ] LTV-indikator-farver (grøn <60%, gul 60-80%, rød >80%)
- [ ] Disclaimer "Tinglyst hovedstol ≠ aktuel restgæld" (T:329)

**Source:** T:317-329

---

### F16: Funding history + valuation (enhjorning.bot-paritet) — ✅ KOMPLET (Phase 1+2+3 LIVE 10/7)

**Phase 1 leveret 9. juli 2026** (registry-api PR #152 + metis-package PR #64): rounds-tabel m. samme-dato ejer-ændringer.

**Phase 2+3 leveret 10. juli 2026** (registry-api PR #153 + metis-package PR #70/#71): rundebeløb, indbetalingsform og implied post-money valuation parses fra CVR's registreringstekster-indeks (kurs ved kapitalændringer; beløb = nominel x kurs/100, valuation = kapital x kurs/100) + valuation-kurve (Chart.js). Metoden er valideret 1:1 mod enhjorning.bot på ROCCAMORE ApS 36542225: total funding 10.364.489,98 DKK matcher EKSAKT, alle valuation-punkter matcher deres kurve. Statstidende-vejen fra det oprindelige estimat blev UNØDVENDIG — registreringsteksterne ligger i samme ES som CVR-data med samme credentials.

**Trigger:** 4. maj 2026 — Frederik observerede enhjorning.bot's funding-feature for danske tech-virksomheder (Resights ApS-eksempel: 3.445.928 DKK total funding, 2 rounds, valuation chart). Dataen er i CVR's `attributter.KAPITAL` + `EJERANDEL_PROCENT`-historik som vi allerede henter; vi extracter den bare ikke endnu.

**Verificeret feasibility 4. maj:** Resights' egen kapital-historik via direkte ES-query:
- 2020-07-16: 300.000 DKK (stiftet)
- 2020-10-23: 365.823 DKK (Round 1: +65.823 nominel)
- 2025-03-04 / 2025-07-25: små justeringer (warrants / nedsættelse)
- 4 ejer-shift-events identificerbare via EJERANDEL_PROCENT med datoer

**Værdi-vinkel:** Resights selv har ikke denne feature; enhjorning.bot er gratis beta. Hvis Metis tilføjer det får vi et **bedre alternativ end både Resights og enhjorning.bot** for tech/PE/M&A-segmentet — komplementerer vores nuværende lender/property-fokus uden at kannibalisere.

**3-fase plan:**

1. **Phase 1 — Structural funding history (2-3 dage)**
   - Backend: parse KAPITAL + EJERANDEL_PROCENT-events → group by date → emit rounds[]
   - Frontend: ny "Funding History"-sektion på selskabsforsiden (under Regnskaber)
   - Output: "X rounds + Y dilution"-tabel med per-round ejer-ændringer
   - **Værdi:** ~50% af enhjorning.bot uden behov for kurs-data; ren strukturel insight allerede bedre end Resights

2. **Phase 2 — Round amounts + valuation (3-5 dage)**
   - Statstidende-API integration (åben, gratis) for verificerede kurs-data pr. kapitaludvidelse
   - Eller: vedtægts-PDF parser for kursfeltet
   - Compute round_amount + cumulative funding + implied post-money valuation
   - Marker tal som "verificeret" (Statstidende) vs "estimeret" (kurs-derivation)

3. **Phase 3 — Charts (1-2 dage)**
   - Capital growth-kurve over tid
   - Ownership stack-chart (hvem ejede hvad-hvornår)
   - Valuation-curve (efter Phase 2)

**Estimat:** 6-10 dage for fuld paritet med enhjorning.bot. Phase 1 alene ~2-3 dage.

**Prioritet:** Lav — under F1 mortgage-alerts, F2 debt-search-paritet og F6 lignende handler. Aktivér når Frederik vil eksperimentere med tech/PE-segment-positionering eller har Phase 1 som hurtig differentiator-demo til fx Mastercard / fintech-fonde.

**Source:** 4. maj 2026 session med Frederik. ES-query-fund commited som verificeret data-tilgængelighed.

---

### F11: Køber-profil-analyse (ejerskifte → demografi) — ❌

**Status:** Prototype eksisterede iflg. F (T:446) men ikke produktiseret. B2B-vinkel mod ejendomsmæglere — IKKE prioriteret for Draupnir/kreditor-segment.

**Estimat:** Parkeret.

**Source:** T:446

---

## P3 — Skip / lav prioritet

### F12: Bygge-modul (offentlige udbud) — ⏭️
Rasmus tester det ikke værd (T:467-485). Skip.

### F13: AI-assistent — ⏭️
Resights AI bruger Rasmus ikke ("aldrig noget brugbart" T:539). Lav forventning. **Men:** Vi kunne bygge en simpel AI-search ovenpå Metis-data der ER bedre end Resights — fordi standarden er meget lav. Senere prioritet.

### F14: Pris som primær differentiator — ⏭️
Moat er sticky-features, ikke pris. Skip som standalone strategi (men 25% rabat aftalt med Rasmus).

### F15: Andelsboligbogen + Bilbogen — ⏭️
Resight har dem (Figur 7), men Rasmus bruger dem ikke aktivt. Skip indtil eksplicit efterspurgt.

---

## Sammenfatning pr. 9. juli 2026

**Total status:**
- P0 done: **2 / 2 fuldt** ✅ (F1 prod-verificeret LIVE, F2 kode-verificeret komplet)
- P1 done: 4 / 5 fuldt (F3 ✅, F4 ✅, F5 ✅, F7 ✅) + F6 🟡 (kun skråfoto mangler)
- P2 done: 2 / 4 fuldt (F8 ✅+, F9 ✅+) + F10 ✅+ partial (LTV-indikator mangler)
- P3: 4 skip ✓

**Alle tre "kritiske blockers" fra 4. maj er lukket.** Rasmus modtager mortgage-digests i praksis og har selv bygget sin watch-portefølje op til 31 aktive watches — det er adfærds-evidens for at sticky-featuren virker.

**Differentiators (bedre end Resight):** gælds-alerts m. daglig mail-digest · omvendt gælds-søgning m. CSV · hierarkisk ejer-visualisering (Reel/Legal) · lejeniveau m. transparent kilde + scenarier · frasolgte ejendomme-afsløring · Grund/Bebygget/Etageareal-distinction · energimærke m. ENS-link.

**Næste konkrete skridt (juli-prioritet):**
1. **Pilot-validering med Rasmus** — statusmøde/demo: F1-digests kører, F2 komplet m. CSV, lignende handler + energimærke nye siden sidst. Afklar: mangler han noget før skifte-beslutning + 20-25% rabat-aftalen aktiveres?
2. ~~Spor 2 UX-huller~~ ✅ LEVERET 9/7: skråfoto (PR #62), LTV-indikator (PR #60), kilde-badges (PR #61) — alle prod-verificeret. Bonus: PR #63 fixede at 'Lignende handler' ALDRIG havde virket i prod (forkert payload-nøgle, Flare 8913035)
3. ~~Spor 3 F16 funding-history Phase 1~~ ✅ LEVERET 9/7 — Phase 2 (Statstidende) / Phase 3 (charts) udestår

**Open questions tilbage at afklare:**
1. Backend-rebrand-strategi (alias eller migration?) — `/v1/debt-search` alias findes; resten uafklaret
2. F8 lejeniveau-kilde-udvidelse (GLR / kunde-indberetning?)
3. `check-batch` endpoint — stadig relevant, eller død idé?

---

<details><summary>Historisk sammenfatning pr. 4. maj 2026</summary>

**Total status:**
- P0 done: 0 / 2 fuldt (begge er 🟦/🟡)
- P1 done: 1 / 5 fuldt (F4) + 2 partial (F3 🟦, F5 🟡, F6 🟦, F7 ✅)
- P2 done: **2 / 4 fuldt** (F8 ✅+, F9 ✅+) + F10 ✅+ partial
- P3: 4 skip ✓

**Kritiske blockers før pilot kan fungere ende-til-ende:**
1. F1 `mortgage_change` alert-detektor + email — Rasmus' eksplicitte sticky-feature
2. F2 owner_type + debt_type filtre + CSV-eksport
3. F6 "Lignende handler"-tabel på ejendomsdetalje (Resight har 376; vi har 0)

**Estimat til "Rasmus klar til skifte":** Best case 3-4 uger; realistisk 5-7 uger.

</details>
