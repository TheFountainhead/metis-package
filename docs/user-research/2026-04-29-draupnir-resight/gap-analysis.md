# Gap-analysis: Metis vs. Draupnir-feedback

**Status pr. 4. maj 2026.** Verificeret mod live `metis.frankston.io` + kode i `metis-package/src/` + `registry-api/app/`.

**Historik:**
- 1. maj 2026: Initial gap-analyse efter 19 PRs siden Draupnir-mødet 29. apr (se git-history)
- 4. maj 2026: Opdateret efter ~25 nye PRs der adresserede flere af gap-items direkte

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

---

### F2: Omvendt søgning på gæld — 🟡

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

---

## P1 — Resight-paritet (kvalitets-vinkler)

### F3: Selskabsforside med portefølje-overblik — 🟦

**Tjekliste:**
- [✅] Nøglepersoner/roller-liste (CompanyRoles section)
- [ ] Nøgletal-boks med 3-årig graf — ❌ ikke implementeret
- [ ] Portefølje-fordeling pie chart (helårsbeboelse / hotel / øvrige) — ❌ ikke implementeret
- [ ] Mini-kort med ejendoms-pins på selskabsforsiden — ❌ ikke implementeret
- [ ] Quick-action links — 🟡 partial via section-headers

**Estimat:** 3-5 dage. Genbrug `Sections/`-pattern.

**Source:** T:370-378, screenshots 01 + 05 (Figur 2+3 i PDF-resumé)

---

### F4: Regnskab som direkte PDF-download — ✅ med caveat

**Status:** PDF-source-detection bygget. Edge-case: store designede regnskaber kan både Resight og Metis fejle.

- [✅] En-klik download fra selskabsside
- [✅] PDF source detection (struktureret vs. designet)
- [ ] Fallback til CVR-kald hvis lokal cache mangler
- [ ] Edge-case håndtering for "designet PDF"

**Source:** T:380-388

---

### F5: Følg-funktion (selskab + ejendom + person) — 🟡

**Tjekliste:**
- [✅] Følg-knap som komponent
- [✅] Personlig følg-liste på /alerts (men kræver token)
- [ ] **Person-watch_type på backend** (kræver GDPR-vurdering)
- [ ] Email-digest (daglig/ugentlig)
- [ ] Trigger-typer for selskab: `new_filing`, `name_change`, `role_change`
- [ ] Trigger-types for ejendom: F1 `mortgage_change` (overlap)

**Estimat:** 3-5 dage.

**Source:** T:489-491

---

### F6: Ejendomsdetalje med "lignende handler" + skråfoto — 🟦 ⚠️ KRITISK MANGLER

**Tjekliste:**
- [ ] **Tabel med 5-10 lignende handler** (kvm-pris, dato, areal, anvendelses-type) — ikke fundet i src/
- [ ] **Skråfoto** (Datafordeleren WMS / SDFE) — kræver datakilde-research
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

## Sammenfatning pr. 4. maj 2026

**Total status:**
- P0 done: 0 / 2 fuldt (begge er 🟦/🟡)
- P1 done: 1 / 5 fuldt (F4) + 2 partial (F3 🟦, F5 🟡, F6 🟦, F7 ✅)
- P2 done: **2 / 4 fuldt** (F8 ✅+, F9 ✅+) + F10 ✅+ partial
- P3: 4 skip ✓

**Differentiators bygget pr. 4. maj (bedre end Resight):**
1. ✅+ **Hierarkisk ejer-visualisering** med Reel/Legal ejer-separation, Udfold struktur, EJF/Tinglysning cross-check
2. ✅+ **Lejeniveau med transparent kilde + scenarier + mixed-use vægtning**
3. ✅+ **Frasolgte ejendomme afsløring** (EJF lagger Tinglysning, vi viser divergensen)
4. ✅+ **Grund/Bebygget/Etageareal-distinction** (Resight viser kun ét areal)
5. ✅ **Omvendt gælds-søgning** (Resight har ikke)

**Kritiske blockers før pilot kan fungere ende-til-ende:**
1. F1 `mortgage_change` alert-detektor + email — Rasmus' eksplicitte sticky-feature
2. F2 owner_type + debt_type filtre + CSV-eksport
3. F6 "Lignende handler"-tabel på ejendomsdetalje (Resight har 376; vi har 0)

**Næste konkrete skridt (sprint-prioritet):**
1. **F1 mortgage_change ende-til-ende (7-10 dage)** — låser sticky-pilot
2. **F6 lignende handler (4-6 dage)** — Resight-paritet for Rasmus' favorit-feature
3. **F2 filter-paritet (3-5 dage)** — kompletter omvendt søgning
4. **Pilot-validering med Rasmus (1 uge)** efter F1+F2+F6 lander

**Estimat til "Rasmus klar til skifte":**
- Best case: 3-4 uger (hvis F1+F2+F6 sprint kører rent)
- Realistisk: 5-7 uger (med pilot-iteration + UX-polering)

**Open questions tilbage at afklare:**
1. Backend-rebrand-strategi (alias eller migration?)
2. F5 person-watch GDPR
3. F8 lejeniveau-kilde-udvidelse (GLR / kunde-indberetning?)
