# Morgen-handoff (1. maj 2026, 22:00 → 08:00 natte-arbejde)

**Til:** Frederik
**Fra:** Claude (Opus 4.7, autonom natte-session)
**Læs i denne rækkefølge:** Dette dokument først → de 6 underliggende artefakter efter prioritet (markeret nedenfor) → beslut → spawn /plan eller /work

---

## TL;DR

**Hvor langt vi er kommet i nat (4 timer effektivt arbejde):**

1. ✅ Verificeret status pr. F1-F11 (Draupnir-bundle 29. apr's templates er nu fyldt ud med faktuel kode-grounding)
2. ✅ Identificeret kritisk **route- og schema-mismatch** mellem `metis-package` og `registry-api` der gør F1+F2 broken end-to-end i prod (selvom UI er bygget)
3. ✅ Researchet Resight + ReData (web + 5 nye videoframes ekstraheret + analyseret)
4. ✅ Bygget data-access-matrix (vi har 85-90% paritet på data, 5+ edges Resight ikke har)
5. ✅ Skrevet samlet design-doc med 10 prioriterede features + 12-uger sprint-plan
6. ✅ Bygget GTM-strategi: positioneringen "Kreditorernes Resight" + segmentering 300-400 organisationer + pricing-model
7. ✅ 3 parallelle review-agenter (code-simplicity, architecture-strategist, best-practices-researcher) har færdiganalyseret spec'en — feedback integreret nedenfor (sektion "Review-agent fund")

**Vigtigste enkelt-finding:** F1 (gælds-alerts) og F2 (omvendt søgning) er bygget på UI-niveau (PR #2, #5 mergeet 29. apr) men virker ikke i prod p.t. fordi `metis-package` kalder `/v1/watchlists`/`/v1/alerts`/`/v1/debt-search`, mens `registry-api` eksponerer `/v1/monitoring/watchlists`/`/v1/monitoring/alerts`/`/v1/mortgages/search`. Plus `MonitoringService` har ingen `mortgage_change`-detektor — Rasmus' kerne-feature er ikke skrevet endnu.

**Vigtigste strategiske finding:** Resight har **0 kunder eksplicit bygget til kreditor-segmentet**. Det er et åbent marked på 300-400 organisationer (~900-4.500 brugere) som matcher præcis Frankstons data-edges (mortgage-delta, AVM, credit, AIS, eSkat). 3-års konservativt potentiale: DKK 9M ARR fra alene kreditor-segment.

---

## Læse-rækkefølge (90 min for fuld review)

### 🔴 Kritisk (læs først, 25 min)
1. **`docs/user-research/2026-04-29-draupnir-resight/phase-A-findings.md`** — verificeret nuværende status, route-mismatch som kritisk finding (~10 min)
2. **`docs/superpowers/specs/2026-05-01-metis-resight-parity-design.md`** — det samlede design (~15 min for første gennemlæsning)

### 🟡 Strategisk (læs efter, 25 min)
3. **`docs/user-research/2026-04-29-draupnir-resight/go-to-market-strategy.md`** — kreditor-positionering, pricing, pilot-strategi (~12 min)
4. **`docs/user-research/2026-04-29-draupnir-resight/competitor-analysis.md`** — Resight + ReData deep-dive med 5 nye videoframes (~13 min)

### 🟢 Reference (slå op efter behov)
5. **`docs/user-research/2026-04-29-draupnir-resight/gap-analysis.md`** — per F1-F11 status (template fra 29. apr nu udfyldt)
6. **`docs/user-research/2026-04-29-draupnir-resight/data-access-matrix.md`** — per data-domæne Frankston vs Resight

### Bilag
- `docs/user-research/2026-04-29-draupnir-resight/screenshots/extracted/08-12*.jpg` — 5 nye Resight-frames fra videoen (person-overblik, Hjem-dashboard, email-notifikation, tinglysning-tab × 2)
- 19 PR-diffs i metis-package (læst alle under Fase A)

---

## Anbefalede beslutninger til dig (i prioritet)

### A. Strategisk positionering (skal besluttes før Sprint 0)

1. **Godkend "Kreditorernes Resight" som primær positionering?**
   - Argument PRO: Resight har det åbne segment, matcher Frankston-edges, undgår bias-mur
   - Argument MOD: Mindre TAM end bredt investor-segment (300-400 vs 1.300+ orgs)
   - **Min anbefaling:** JA, positionér først og udvid efter pilot-cases

2. **Pricing-tier-struktur?**
   - Pro 18K / Creditor 30K / Enterprise 25K (volumen-rabat)
   - **Min anbefaling:** Godkend som start; juster efter første 3 pilot-deals

3. **9-måneders struktureret pilot-aftale med Rasmus?**
   - Inkluderer kvartalsvise feedback-checkpoints + skifte-tærskel
   - **Min anbefaling:** JA — risikoen for "supplement only" mitigéres af krav om monatlig feedback-session

### B. Sprint 0 kick-off (skal besluttes denne uge)

4. **Backend-rebrand: rename eller alias?**
   - Anbefaling: rename `monitoring/*` → top-level + 1-uges alias-overgangsperiode
   - Begrundelse: ingen kendte konsumenter; "monitoring" er forkert konceptuelt for user-feature

5. **Alert-type granularitet?**
   - 5 typer (mortgage_change, new_lien, creditor_change, principal_change, ownership_change) eller 2-3?
   - Min anbefaling: 5 (giver UI-filtrering + priority-sortering)
   - *⏳ Awaiting feedback fra code-simplicity review-agent*

6. **Person-watch GDPR-clearance?**
   - Skal afklares med jurist FØR Sprint 2 (uge 3)
   - Backup: kun navn + CVR-roller, ikke CPR

### C. Eksekvering (afgør sales-kapacitet)

7. **Frederik-sales-bottleneck:** 50-person prospect-outreach kan ikke køres parallelt med sprint-koordinering. Skal vi:
   - (a) hire 1 sales-person når 3 pilots er signed?
   - (b) delegere outreach til Kristian eller Jens i stille perioder?
   - (c) gennemføre sales i koncentrerede 2-uger sprint efter hver feature-LIVE?
   - **Min anbefaling:** (c) først, så (a) når MRR er der

8. **Næste pilot-prospect efter Rasmus?**
   - Forslag: Faktorkredit (eksisterende Frankston-relation, lav friktion)
   - Eller: 1 advokat-kontor (Bech-Bruun insolvens, høj smerte → høj betalingsvilje)
   - Eller: Mastercard-partner-channel intro til realkredit-institut

---

## Open questions der ikke har klart svar

1. **F2 owner_type datakilde:** Vi har ikke verificeret om `properties.primary_owner_type` findes eller skal udledes via `property_owners`-join. Skal undersøges Sprint 0 dag 1.

2. **F1 mortgage_snapshots performance:** Antal aktive mortgages × antal fulgte properties × 365 dage kan blive en stor tabel. Sandsynligvis skal partitioneres pr. måned. Confidence 75 — afventer review-agent input.

3. **Embedded vs standalone roadmap:** Skal Frankston-master's `/metis`-implementering opdateres parallelt med standalone, eller efter pilot-validering? Spec siger "efter", men det betyder Dannebrog/Draupnir-kunder i Frankston-master ikke får F1+F2 før uge 13+.

4. **Lejeniveau-data:** Bevidst valg at IKKE bygge 135K observationer. Men bør vi have minimum-MVP via Boliga-annoncer + manuel indberetning fra kunder selv? Eller fuld skip i 12 uger?

5. **AI-assistent:** Resights er svag (T:539). Vi kunne bygge en simpel MCP-server der bare er bedre. Men det er ikke i sprint-plan og kunne distrahere fra kerne-features. Anbefal: park indtil sprint 6+.

6. **Pricing-MVP:** Spec foreslår offentlig pricing-page (modsat Resight kontakt-sales). Men kreditor-segmentet er enterprise, og enterprise-deals laves ofte uden public pricing. Beslut: offentlig pricing eller kontakt-sales?

---

## Hvad jeg IKKE nåede i nat

1. **Pilot-outreach-script** til 50 kreditorer — start på dette Sprint 0 dag 5+ (skal Frederik køre, ikke autonom-Claude)
2. **Konkret feature-flag-strategi** for Sprint 0 mortgage-delta rollout — kun nævnt i spec, ikke designet
3. **Pricing-page mockup** for metis.frankston.io — skitseret konceptuelt men ikke designet
4. **Konkret onboarding-flow** for kreditor-pilot — outline i GTM-strategi, men ikke step-by-step
5. **Webhook/integration-API** for kreditorer der vil pushe alerts ind i deres egne CRM-systemer — vigtig for enterprise-deals, ikke i 12-uger plan

Disse skulle have været i Fase E hvis natten var længere — anbefales at dækkes i en `/plan`-session i morgen.

---

## Risici jeg er bekymret for

| Risiko | Hvorfor det bekymrer mig |
|---|---|
| **Kristian + Jens kapacitet** i 12 uger | Jens er 80% på Verdi. Kristian alene kan ikke køre Sprint 0+1+2 parallelt med øvrige opgaver. |
| **Rasmus' "kun supplement"-mønster** (T:766) | Selv med 9 mdr pilot-aftale risikerer vi at han bruger Metis kun til F1+F2 og ikke skifter. Skal have meget tæt loop. |
| **Resight reagerer hurtigt** | 45 ansatte, 16M overskud — hvis de prioriterer mortgage-delta, kan de bygge på 4-6 uger. Vi skal LIVE før de mærker truslen. |
| **Backend-rebrand 1-uges alias-vinduet** | Hvis nogen ekstern konsument ringer, skal vi reagere hurtigt. Behov for monitoring på `monitoring/*` paths. |
| **Pilot-segment ikke pre-warmed** | Outreach-arbejde starter fra nul. 50-person prospect-list eksisterer ikke endnu. Sales-cycle 60-90 dage betyder pilots underskrives kalendermæssigt sent i sprint-planen. |

---

## Konfidens-tabel (samlet for hele natte-arbejdet)

| Artefakt | Confidence | Begrundelse |
|---|---|---|
| Phase A findings (kode-status) | 95 | Direkte kode-verificeret, citationer per linje |
| Gap-analysis | 90 | Per-F-feature udfyldt, men ~5 antagelser fra memory der bør visuelt verificeres |
| Competitor analysis Resight | 80 | Web-WAF blokerer direkte scraping, så kombineret videoframes + 3rd-party. Pricing er rekonstrueret fra T:782. |
| Competitor analysis ReData | 88 | Public pricing + produkt-side direkte hentet |
| Data-access matrix | 85 | Solidt grounded i registry-api routes + memory; 2-3 felter kræver verifikation (pant_type backfill, owner_type lokation) |
| Design spec arkitektur | 82 | Solid foundation; reviewer-agent-feedback indkommer kort |
| GTM markedsstørrelse-estimat | 70 | Konservativt grounded, men capture-rate-antagelser usikre |
| Sprint-plan realisme (12 uger) | 73 | Realistisk hvis Kristian + Jens i fuld kapacitet — Verdi-konflikt med Jens er reel |

**Samlet handoff-confidence:** ~83 — solidt nok grundlag til at træffe strategisk beslutning, men 2-3 åbne tekniske/strategiske valg skal afklares før Sprint 0 starter.

---

## Konkret næste-skridt-forslag

1. **I morgen formiddag (90 min):**
   - Læs handoff + spec + GTM (3 dokumenter)
   - Beslut spørgsmål A (1-3 strategiske)
   - Beslut spørgsmål B (4-6 tekniske/sprint)
   - Hvis JA til design: spawn `/plan` skill mod spec → konkret task-liste

2. **I morgen eftermiddag:**
   - Hvis person-watch er Sprint 2: book GDPR-jurist-call denne uge
   - Tilføj 50-person prospect-list på din TODO-liste (LinkedIn Sales Navigator + Tinglysning data → 2-3 timers arbejde)
   - Send heads-up til Kristian + Jens om Sprint 0 indhold

3. **Mandag:**
   - Sprint 0 kick-off: backend-rebrand PR (Kristian)
   - Bestil pilot-token til Rasmus med F1+F2 forventet LIVE 9 dage senere

---

## Review-agent fund (læs FØR du godkender spec'en)

Tre uafhængige review-agenter analyserede spec'en efter den var skrevet. De er ikke 100% enige indbyrdes, men giver tre stærke perspektiver. Hver er værd at læse i sig selv, så her er en sammenfatning af de vigtigste fund:

### Code-simplicity reviewer — "Specen lider af alt-skal-med-syndrom"

**Største fund:**
1. **Sprint 0 er over-loaded** (8 tasks á 0.5-3 dage = 9 dage faktisk arbejde for Kristian alene). **Anbefal split til Sprint 0a (rebrand + validators, uge 1) + Sprint 0b (mortgage-delta engine + snapshot, uge 2).** Pilot-token til Rasmus rykkes fra dag 7 til dag 10.

2. **5 alert-types er over-granular.** Kollaps `creditor_change` + `principal_change` + `mortgage_change` til én — selve description-feltet bærer detaljen ("Principal ændret 5M → 8M"). **Anbefal 3 typer:** `new_lien`, `mortgage_change`, `ownership_change`.

3. **Cut fra 12-uger-scope:**
   - F-NEW2 Hjem-dashboard (5-7 dage) — visuel paritet, ikke retention-driver
   - F8 kilde-mærkning (2-3 dage) — marketing-spil, ingen kreditor har efterspurgt
   - F9 hierarkisk ejer-vis (4-5 dage) — defer post-pilot, Resight-spider er stadig der som fallback
   - F-NEW3 Lister (3-4 dage) — defer; spørg Rasmus om det er deal-breaker FØR du bygger

4. **Trim:** F3 selskabsforside fra 5 widgets → 3 (cut map + pie chart).

**Total reduction: 15-20 dage frigjort.** Skaber buffer til Rasmus-feedback-iteration som specen confidence-scorer 73 på.

### Architecture-strategist — "5 fund, 2 kritiske"

**Største fund:**
1. **Backend-rebrand 1-uges alias er for kort.** Spec hævder "controlleren er ikke i brug" men har ikke auditeret Frankston-master, Trust, Faktorkredit + 8 andre tenant apps. **Canonical pattern: Sunset/Deprecation HTTP headers (RFC 8594/9745) + 30-dages audit, eller bump til `/v2/*`.** Det her er præcis "gæt aldrig"-regel-overtrædelsen.

2. **`mortgage_snapshots` er forkert pattern.** Daily full-snapshot pr. fulgt ejendom = 1.8M rows/år ren duplikat-data. **Anbefal event-sourcing pattern: `mortgage_events` table** med `before_jsonb`/`after_jsonb`/`occurred_at` appended af tinglysning-crawler. Eksisterende `spatie/laravel-activitylog` kan bruges (allerede i NemComply per memory). Derudover løser det "follow added today, want last week's diff"-use-case spec'en ikke adresserer.

3. **Embedded-vs-standalone er stor blind-spot.** Spec lumper begge i ét component-diagram, men reality er at Frankston-master har egne `app/Livewire/Metis/*`-komponenter med `FrankstonApi`. **Sprint 0 task: `grep -r "v1/monitoring" Frankston-master/`** før nogen route-deletion. Long-term: ekstrahér `MetisRegistryClient`-interface med `RegistryApiAdapter` (standalone) + `FrankstonApiAdapter` (embedded).

4. **F-NEW Portfolio-Tinglysning er underspecificeret.** Server-side paginering fra row 1, ikke ved >100. Backend-first: definér `GET /v1/companies/{cvr}/portfolio-mortgages?per_page=50&sort=principal_desc` med aggregate-envelope FØR UI bygges.

5. **Email-digest skal være Horizon-queue, ikke synkron cron.** Ellers risikerer vi den 9-silent-failures-i-træk-pattern fra `project_scheduled_command_monitoring.md`. Per-user job + idempotency key + dead-letter til Flare.

### Best-practices researcher — "Spec'en er canonical for 70% af det den prøver"

**Største fund:**
1. **Snapshot + diff-pattern er FAKTISK canonical** for "snapshot-only sources" (Databricks AUTO CDC FROM SNAPSHOT, AWS Config). Det matcher Metis' 3rd-party crawl. **MEN architecture-strategist har en valid kritik om event-sourcing er bedre — beslut nedenfor.**

2. **Optimerings-anbefalinger til snapshot-pattern (hvis vi beholder den):**
   - Tilføj `row_hash` kolonne (sha256 af significant cols) for 100x hurtigere diff
   - Idempotency-key `(property_id, snapshot_date)` med `INSERT ... ON CONFLICT DO NOTHING`
   - Missed-day recovery: diff vs `last_available_snapshot`, ikke `today - 1` (hvis cron misser onsdag, torsdag's run skal stadig produce korrekt delta)
   - Partition `mortgage_snapshots` by month
   - Backfill med dag 1 snapshot ved deploy

3. **Email-digest: brug Laravel Notifications + Horizon, ikke raw Mailable + cron.**
   - Hourly cron-job + per-user "current local hour matches preferred hour"-check (Rappasoft pattern) → time-zone-aware
   - `digest_runs` table med unique `(user_id, digest_date)` for idempotency
   - Empty-state gating før queue-dispatch (no email = no SES burn)
   - Default tz=Europe/Copenhagen, hour=8

4. **Skip Spatie/owen-it auditing for tinglysning-deltas** (de fyrer på Eloquent events, fungerer ikke pålideligt for bulk upsert). Reservér til Watchlist/Alert user-edit trail.

### Hvad er konflikten mellem reviewers?

**Architecture-strategist** anbefaler **event-sourcing** (`mortgage_events` table med før/efter JSON).
**Best-practices** bekræfter **snapshot + diff** er canonical for snapshot-only sources.

Begge har valid argumenter:
- Event-sourcing løser "follow added today, want backfill of last week's diffs"-problem
- Snapshot+diff er enklere og matches af AWS Config / Databricks-pattern

**Min anbefaling:** **hybrid** — start med snapshot+diff (enklere implementation) PLUS append `mortgage_events` rows ved hver detected delta. Det giver os:
- Nu: simpelt delta-engine
- Senere: event-log til audit/historic backfill
- Begge tilgange's fordele uden at vælge én

Snapshot bliver "current state cache for diff-engine"; events bliver "permanent record of all changes".

### Sammenfattet konsensus mellem reviewers

| Action-item | Code-simpl | Architecture | Best-practices | **Anbefaling** |
|---|---|---|---|---|
| Sprint 0 split 0a+0b | ✅ | (neutral) | (neutral) | **Yes — split** |
| Cut F-NEW2 Hjem | ✅ | (ikke rejst) | (ikke rejst) | **Yes — cut** |
| Cut F8 light | ✅ | (ikke rejst) | (ikke rejst) | **Yes — cut** |
| Defer F-NEW3 Lister | ✅ | (ikke rejst) | (ikke rejst) | **Yes — defer** |
| Defer F9 hierarkisk | ✅ | (ikke rejst) | (ikke rejst) | **Yes — defer** |
| Reduce 5→3 alert-types | ✅ | (ikke rejst) | (ikke rejst) | **Yes — 3 typer** |
| Trim F3 5→3 widgets | ✅ | (neutral) | (ikke rejst) | **Yes — trim** |
| Backend rebrand 1-uges alias er forkert | (ikke rejst) | ✅ Sunset-headers eller /v2 | (ikke rejst) | **Yes — bump til /v2 eller Sunset 30 dage** |
| Audit ALLE Frankston repos for monitoring/* | (ikke rejst) | ✅ | (ikke rejst) | **Yes — Sprint 0 dag 1** |
| Snapshot vs event-sourcing pattern | (ikke rejst) | Event-sourcing | Snapshot canonical | **Hybrid: snapshot+diff PLUS mortgage_events log** |
| Email-digest queue per-user | (ikke rejst) | ✅ Horizon | ✅ Notifications + Rappasoft | **Yes — Horizon queue + Rappasoft pattern** |
| F-NEW Portfolio-Tinglysning backend-first | (ikke rejst) | ✅ | (ikke rejst) | **Yes — define API før UI** |

**Net-resultat for spec'en:** ~15-20 dage cut + 4 arkitektur-rettelser. Sprint-plan reduceres fra 12 uger til realistisk **10 uger** med større buffer + højere kvalitet.

---

## Closing note

Arbejdet i nat har bekræftet at Metis-projektet er stærkere end gap-analysen-template fra 29. apr antydede, og samtidig at de 19 PRs på dagen efter mødet skulle have inkluderet backend-arbejdet for at låse pilot-værdi. Det er en lille koordinations-rettelse, ikke et arkitektur-problem.

Den mere interessante indsigt er at vi har **et marked uden direkte konkurrent** — kreditorernes Resight findes ikke. Det er en større strategisk vinkel end "konkurrer mod Resight på paritet", og den udnytter Frankstons regulerings-trappe (eSkat, AIS, betalings-tilladelse) som ingen andre konkurrent kan kopiere.

Min stærkeste anbefaling: **bekræft kreditor-positioneringen i morgen** før noget andet. Den afgør hele design-doc'ens prioritering. Hvis du afviser den, skal sprint-planen omprioriteres væsentligt.

Spawn `/plan` på spec'en når du har godkendt → så har vi en task-by-task implementeringsplan klar mandag morgen.

— Claude
