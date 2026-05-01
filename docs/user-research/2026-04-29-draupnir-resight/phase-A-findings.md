# Phase A: Verificeret status pr. 1. maj 2026

**Formål:** Faktuel "hvad er bygget vs. hvad mangler" pr. F1-F11. Hver påstand citerer kilde (`file:line` / `T:linje` / `Registry-API GET /v1/...`). Ingen kilde = item ikke baked-in.

**Metode:** Læst alle 19 PR-diffs i `metis-package`, sourcekode i `metis-package/src/`, route-fil i `registry-api/routes/api/v1.php`, controller-implementation, `MonitoringService`, plus live-curl af `metis.frankston.io/{,/soeg,/alerts}`.

---

## 🚨 Top-finding — kritisk for at F1+F2 fungerer i prod

**Route- og schema-mismatch mellem `metis-package` og `registry-api`.** Begge UI-komponenter (DebtSearch, AlertsInbox, FollowButton) er merget til `metis-package/main` 29. apr (PR #2, #5). Men de kalder backend-endpoints som *ikke eksisterer* under den path og under den schema som `registry-api/main` p.t. eksponerer.

| Metis-package kalder | Registry-API har | Status |
|---|---|---|
| `GET /v1/watchlists` | `GET /v1/monitoring/watchlists` | ❌ 404 |
| `POST /v1/watchlists` | `POST /v1/monitoring/watchlists` | ❌ 404 |
| `DELETE /v1/watchlists/{id}` | `DELETE /v1/monitoring/watchlists/{id}` | ❌ 404 |
| `POST /v1/watchlists/check-batch` | (ingen) | ❌ 404 — endpoint findes slet ikke |
| `GET /v1/alerts` | `GET /v1/monitoring/alerts` | ❌ 404 |
| `PATCH /v1/alerts/{id}/read` | `PATCH /v1/monitoring/alerts/{id}/read` | ❌ 404 |
| `GET /v1/debt-search` | `GET /v1/mortgages/search` | ❌ 404 (men funktionalitet eksisterer) |
| `POST /v1/debt-search/export-link` | (ingen) | ❌ 404 — CSV-eksport ikke bygget |

**Schema-mismatch:**
- `watch_type`: metis sender `property|company|person`. Backend validator (`MonitoringController.php:19`) accepterer kun `property|postal_code|company`. **`person` ikke understøttet i backend.**
- `alert_types`: metis ville sende `mortgage_change`/`new_lien`/`tinglysning_delta` for at understøtte F1's gælds-alert. Backend validator (`MonitoringController.php:23`) accepterer kun `transaction|ownership_change|valuation|new_listing`. **F1's eneste sticky-differentiator (gælds-delta) findes ikke som alert-type i backend.**

**Hvor er det implementeret:**
- `MonitoringService::checkProperty` (registry-api `app/Services/Monitoring/MonitoringService.php:47-112`) detekterer kun `transaction` (handelsdata) + `ownership_change` (indirekte transaktioner). **Ingen mortgage/tinglysning-delta-detektion. Det er den FAKTISKE Rasmus-feature og den er ikke skrevet endnu.**

**Konsekvens:** F1 (debt alerts) og F2 (omvendt søgning) er bygget på UI-niveau men *fungerer ikke end-to-end* mod nuværende `registry-api/main`. Pilot-tokens på /alerts kan stadig vise tom inbox, men:
- Ingen FollowButton-toggle vil persistere (mount → `checkBatch` → 404 → silent fail-state "ikke fulgt")
- "Søg gæld" på /soeg vil 404 første gang en bruger trykker "Søg" (medmindre `RegistryApi::debtSearch()` 422-håndteres som "ingen resultater")

**Påkrævet før pilot kan fungere:**
- Registry-API: Tilføj `/v1/watchlists`, `/v1/alerts`, `/v1/debt-search` aliaser (eller migrer alt under `/v1/monitoring/*` og opdatér metis-package). Plus `check-batch` endpoint.
- Registry-API: Udvid `watch_type` enum med `person` + alert_types med `mortgage_change`, `new_lien`, `creditor_change`.
- Registry-API: Implementér `MonitoringService::checkMortgageDelta()` der dagligt sammenligner `mortgages` for `is_active=true` per fulgt ejendom og fyrer alerts på add/remove/principal-change/creditor-change.

---

## Hvad er reelt build-state pr. feature

**Legend:** ✅ Done · 🟡 UI bygget men ikke end-to-end · 🟦 Foundation lagt, kerne mangler · ❌ Ikke startet

### F1: Alerts på gælds-ændringer pr. fulgt ejendom — 🟦
- ✅ FollowButton.php (`src/Livewire/FollowButton.php:1-88`) — toggle UI
- ✅ AlertsInbox.php (`src/Livewire/AlertsInbox.php:1-143`) — inbox UI
- ✅ /alerts route med pilot-token-gate (PR #6)
- ✅ Watchlist + Alert models + cron-cmd `RunMonitoringCommand` (registry-api)
- ✅ `transaction` + `ownership_change` (indirekte) detektorer
- ❌ **`mortgage_change` detektor — selve Rasmus' use case (T:497 "ny ejerpand")**
- ❌ Email-notifikation ved nye alerts
- ❌ Differentiation ejerpantebrev (low) vs udlæg (high) i UI
- ❌ Detalje-side med "før vs. nu" diff
- ❌ Route- og schema-mismatch (se top)

**Hvor langt:** UI 80%, backend-foundation 50%, kerne-feature 0%. Estimat 4-7 dage til reel gælds-delta-alert i prod.

### F2: Omvendt søgning på gæld — 🟡
- ✅ DebtSearch.php (`src/Livewire/DebtSearch.php:1-237`) — filter-UI med min/maxRate, ownerType, debtType, postalCodeRange, creditorContains, min/maxAmount
- ✅ MortgageSearchController.search (registry-api `app/Http/Controllers/Api/V1/MortgageSearchController.php:15-90`) — backend-eksekvering på `mortgages` tabel
- ✅ Aktivt mortgage-flag (`is_active`), creditor-fuzzy (ilike), postal-range, principal/rate-intervaller
- 🟡 **Route-mismatch /v1/debt-search vs /v1/mortgages/search**
- ❌ `owner_type` (selskab vs privatperson) — kræver join med property_owners + ejer-type-klassifikation
- ❌ `debt_type` (ejerpantebrev vs realkredit vs privat pantebrev) — kræver klassifikations-felt på `mortgages`
- ❌ CSV-eksport endpoint (`POST /v1/debt-search/export-link`)
- ❌ Gem-søgning + alert (overlap med F1)
- ⚠️ Coverage: ~33% af ejendomsmassen iflg. F's demo (T:255). Ejer "Off. vurdering / Seneste handel / Tinglyst gæld" kolonner blev tilføjet i PR #19.

**Hvor langt:** UI 90%, backend-foundation 70%, kerne-filtre (owner_type, debt_type) 0%, CSV 0%. Estimat 3-5 dage til reel feature-paritet.

### F3: Selskabsforside med portefølje-overblik — 🟦
- ✅ CVR-opslag inkl. virksomhedsinfo, regnskaber, roller, ejerstruktur, ejendomme (memory bekræfter v2.6.0)
- ✅ Property portfolio paginering, BBR/VUR berigelse, kort-view
- ❌ **Pie chart med portefølje-fordeling** (anvendelses-type breakdown)
- ❌ **Nøgletal-boks (omsætning/balance/EK)** som ét view, før klik
- 🟡 **Nøglepersoner-boks** — er der bestyrelses-info? Verificering nødvendig
- ❌ Direct quick-links til regnskab/ejendomsliste/tinglysning fra forsiden i Resight-stil

**Estimat:** 3-4 dage hvis der bygges i `Sections/`-pattern.

### F4: Regnskab som direkte PDF-download — ✅ (med caveat)
- ✅ Memory bekræfter PDF-source-detection bygget i v2.6.0
- ⚠️ Edge-case: Større/designede regnskaber er svære for både Resight og Metis. T:380 nævner Resight fejler her. Verificér Metis' fallback (CVR-kald?).

### F5: Følg-funktion (selskab + ejendom + person) — 🟡
- ✅ FollowButton komponent generisk (watchType + watchValue + displayLabel)
- ✅ /alerts inbox UI
- ❌ Person-watch_type IKKE understøttet i backend (`MonitoringController.php:19`)
- ❌ Email-digest (kun in-app alerts)
- ❌ Trigger-typer for selskab: `new_filing` (regnskab landed), `name_change`, `role_change` — ingen af dem er implementeret i `MonitoringService::checkCompany`

### F6: Ejendomsdetalje med "lignende handler" + skråfoto — 🟦
- ✅ BBR + vurdering + ejere + transaktioner i lookup
- 🟡 PR #19 tilføjede "Off. vurdering / Seneste handel / Tinglyst gæld" kolonner i ejer-portefølje (= F10 partial)
- ❌ "Lignende handler"-modul (kvm-pris-baseret nabolags-similar-trades)
- ❌ Skråfoto + matrikelkort side-om-side (Datafordeleren WMS / SDFE Skråfoto?)

### F7: Person-søgning med ejer-roller + ejendomsmasse — ✅
- ✅ Memory bekræfter person-søgning bygget med roller + ejede selskaber + ejendomsantal (147 ejendomme for Bisgaard-Frantzen)
- ✅ PR #15 unified person-property portfolio view
- 🟡 Person-FOLLOW IKKE muligt (backend mangler watch_type=person)

### F8: Lejeniveau med kilde-mærkning — ❌
- ❌ Ikke i v2.6.0
- ⚠️ Forudsætter at vi bygger eller licenserer faktisk lejedata. Resights eget data er delvist annonce-antaget (T:281). Kilde-strategi nødvendig (Boliga annoncer + GLR + manuel indberetning?).

### F9: Hierarkisk ejer-visualisering (ikke spider) — ❌
- ❌ Ikke implementeret. Memory nævner ejerstruktur men ikke som hierarki-træ med kollaps. Resights spider er den nuværende paritet.

### F10: Vurdering + tinglyst hovedstol side-om-side — 🟡
- ✅ PR #19 tilføjede "Off. vurdering" + "Tinglyst gæld" kolonner — implementeret som **portefølje-tabel** kolonner
- ❌ Mangler **ejendomsdetalje-view** med samme + LTV-indikator + disclaimer
- 🟡 Disclaimer "Tinglyst hovedstol ≠ aktuel restgæld" (T:329) ikke synlig i UI

### F11: Køber-profil-analyse (ejerskifte → demografi) — ❌
- ❌ Ikke i v2.6.0. F nævner prototype-eksperiment med ejendomsmægler (T:446) men det blev ikke produktiseret.

---

## Verified-vs-Assumed tabel

| Påstand | Status | Kilde |
|---|---|---|
| F1 UI er bygget og merget | ✅ Verificeret | `git log feature/debt-alerts-v1` + PR #5 + `src/Livewire/AlertsInbox.php:1-143` |
| F1 backend mangler mortgage_change-detektor | ✅ Verificeret | `registry-api/app/Services/Monitoring/MonitoringService.php:60-105` (kun transaction + ownership_change) |
| F1 backend mangler person watch_type | ✅ Verificeret | `MonitoringController.php:19` validator |
| Route-mismatch mellem package og registry-api | ✅ Verificeret | `RegistryApi.php:421-510` vs `routes/api/v1.php:106-110` |
| /alerts kræver pilot-token | ✅ Verificeret | live curl + `AlertsInbox.php` `hasUserToken` gate |
| F2 UI er bygget og merget | ✅ Verificeret | PR #2 + `src/Livewire/DebtSearch.php:1-237` |
| F2 backend mortgage-search funktionalitet er der men under anden URL | ✅ Verificeret | `MortgageSearchController.php:15-80` |
| F2 mangler owner_type filter på backend | ✅ Verificeret | Schema validator har ikke owner_type |
| F2 mangler CSV-eksport endpoint | ✅ Verificeret | grep `export-link` i registry-api → 0 results |
| F3 (selskabsforside) status | ⚠️ Antaget fra memory (29 dage gammel) | `project_metis_standalone.md` — kræver visuel verifikation natten over |
| F4 (regnskab-PDF) status | ⚠️ Antaget fra memory | `project_metis_standalone.md` |
| F5 (følg-funktion) email-digest mangler | ✅ Verificeret | grep `mail\|notification\|digest` på MonitoringService → 0 results |
| F6 lignende handler mangler | ⚠️ Antaget — ikke fundet i src/ | grep `similar\|lignende` på src/Livewire/Sections/ → 0 results (kræver verifikation) |
| F8 lejeniveau-kilde-mærkning mangler | ⚠️ Antaget — ikke fundet | kræver visuel verifikation |
| F9 hierarkisk ejer-visning mangler | ⚠️ Antaget — ikke fundet | kræver visuel verifikation |
| F10 LTV på ejendomsdetalje (ikke kun portefølje) mangler | ✅ Verificeret | PR #19 var portefølje-tabel kolonner, ikke ejendomsdetalje |

---

## Nyligt build-momentum (29. apr — 1. maj 2026)

| Dato | PR | Indhold |
|---|---|---|
| 29 apr 05:59 | #2 | DebtSearch UI (594 lines) |
| 29 apr 07:43 | #3 | Postal range + prev-page + address details polish |
| 29 apr 07:59 | #4 | Strip partial filters before API call |
| 29 apr 13:28 | #5 | F1 Debt Alerts UI: FollowButton + AlertsInbox (400 lines) |
| 29 apr 13:55 | #6 | Session token override for per-user F1 pilot |
| 29 apr 14:03 | #7 | Watchlist count + list on /alerts |
| 29 apr 14:08 | #8 | Hide internal IDs in watchlist UI |
| 29 apr 14:18 | #9 | Suggestions when search returns no_results |
| 29 apr 14:57 | #10 | Resight-style left sidebar (142 lines) |
| 29 apr 15:04 | #11 | Sidebar Tailwind colors |
| 29 apr 15:12 | #12 | Type-first search UX |
| 29 apr 15:17 | #13 | Type-first locks query type |
| 29 apr 19:55 | #14 | Stale result clearing |
| 29 apr 20:52 | #15 | Unified person-property portfolio view |
| 29 apr 21:28 | #16 | x-metis-link empty slot fix |
| 29 apr 21:38 | #17 | loadMore reflection fix |
| 29 apr 21:41 | #18 | BFE fallback when address null |
| 29 apr 22:00 | #19 | Off. vurdering / Seneste handel / Tinglyst gæld kolonner |

**Observation:** 18 PRs på én dag (29. apr) efter Draupnir-mødet. Hastigheden er imponerende men også hvorfor route-mismatchen er let at forstå — UI-PRs blev mergeet uden at backend-PRs landede synkront. Det er et coordination-mønster der skal addreseres i den kommende sprint.

---

## Konsekvenser for Fase B-E

1. **Højeste prioritet i Fase C:** Backend-arbejdet i registry-api (route-aliaser, alert_types-udvidelse, MonitoringService::checkMortgageDelta). Det er den ENESTE feature der låser Rasmus' "klar-til-skifte". UI er allerede bygget — der mangler "kun" backend.

2. **Anden prioritet:** F2 owner_type + debt_type + CSV-eksport. Det færdiggør den anden af to differentiator-features.

3. **Tredje prioritet (paritet):** F6 lignende handler, F9 hierarkisk ejer-vis, F10 ejendomsdetalje-LTV — Resight-paritet hvor vi taber til Resight i kvalitet, ikke data.

4. **Open question for B-E:** Er der pilot-feedback fra de pilot-tokens der er udstedt? Det vil afgøre om "bygget men ikke testet"-features faktisk er brugbare.

---

## Open questions du skal beslutte i morgen (vokser i E)

1. **Backend-rebrand-strategi:** Migrér registry-api fra `/v1/monitoring/*` → `/v1/*` (breaking change for andre konsumenter?), eller opdater metis-package til `/v1/monitoring/*` (UI-arbejde tilbage på 0)? Anbefalet: rebrand backend, da `monitoring/` præfiks ikke giver mening når watchlists er en *user-feature*, ikke en operationel monitoring-feature.

2. **F1 alert_types fragmentering:** Skal `mortgage_change` være ÉN type (frontend filtrerer) eller flere granulære (`new_mortgage`, `removed_mortgage`, `principal_change`, `creditor_change`)? Granulær er bedre for prioritization (udlæg = high, nyt ejerpantebrev = medium).

3. **Person watch_type (F5+F7):** Er der GDPR-implikationer ved at tillade alerts på CPR? Verificér med juridisk vinkel.

4. **F2 owner_type — datakilde:** Vi har ikke ejer-type-klassifikation færdig. Hvor får vi "ejet af selskab"-flag fra? CVR-relation? Tinglysning?
