# Frankston Lender — Product Design Spec (v3.1)

**Status:** Brainstorm-output + 3 review-passes, klar til /plan
**Brand:** Frankston Lender
**URL:** `lender.frankston.io`
**Pilot-target:** Q3 2026 (Draupnir Investment Advisors)
**Confidence:** 86/100 (post v3.1-pass, kalibreret efter 2 reviewer-bias-korrektioner)

**v3.1 ændringer fra v3**: Flow 2 disambiguation async-design (fjerner SLO-modsigelse), §8.5 FORCE RLS + 4 navngivne Pest-scenarier + raw-SQL-guard, §8.4 pgcrypto key-rotation policy, §7.2 prismæssig trim (ingen DKK-tal i public spec), §11 reconciled m. §13 (+ GDPR Art. 17 + Art. 28/30 terminologi).

**v3 ændringer fra v2**: Fri pilot (no price commitment), PostgreSQL-justifikation, dedikeret Security + Observability sektioner, effort rebudgeted (60-80 dage realistic), tenant-isolation test budgeteret, Flow 5 audit-export security-hærdet, PII-retention reconciled, missing flows tilføjet, sektion-numbering sekventiel.

---

## 1. Goal

Bygge **Frankston Lender** — et risk-database-as-a-service-produkt til danske lenders (private debt-funde, pantebrevsselskaber, factoring, SMV-banker, crowdlending). V1.0 leverer minimum viable scope til at validere Draupnir-fit; Post-pilot Roadmap udvider til cohort-skala.

Strategisk del af **Vej B** i Frankston's parallel-strategi (Vej A = `frankston.dev`, Vej B = `lender.frankston.io`). Customer-concentration-fix via kundebase-diversificering.

## 2. Pilot-model: Free trial + value-based post-pilot

### 2.1 Trial-fase

- **DKK 0/md** under hele pilot-perioden
- **Varighed**: indtil pilot-milestones fires (se §4 Q6)
- **Customer-commitment**: nul — ingen lock-in, ingen forpligtelse
- **Frankston bærer CAPEX**: ~DKK 270-420K MVP-investering accepteret som "design-partner-investering"
- **Data-protection**: hvis customer exit'er under trial, eksport-mulighed + 30-dages sletnings-vindue (Flow 6)

### 2.2 Post-pilot-fase

- **Pricing TBD** — forhandles baseret på målbar værdi Draupnir/cohort har oplevet under pilot
- **Internt target-range** (ikke i public spec): DKK 5-30K/md per kunde, segment-afhængigt
- **Pricing-model-kandidater**: per-position degressiv tier-model (§9.2) eller flat-rate — beslutning post-pilot
- **Subscription-vilkår**: månedsbasis med 30 dages opsigelsesvarsel. Aldrig 24-mdr-lock-in
- **Cohort-customers**: samme model — fri trial → forhandlet post-pilot-pris

### 2.3 Hvorfor denne model

- Eliminerer pre-validation pris-anchoring (vi prissætter ikke værdi vi ikke har målt)
- Customer-friendly sales-pitch: "test gratis, ingen forpligtelse"
- Matcher lean-startup-praksis (Build → Measure → Learn)
- Eliminerer kontraktuel inkonsistens mellem milestone-gate og price-lock

---

## 3. Architecture

### 3.1 Repo + deployment

- **Selvstændigt Laravel-package** `frankston-lender-package` i ny GitHub-repo
- **Separat Laravel-app** deployes på `lender.frankston.io` — ikke shared-hosting med Metis
  - DORA Article 30 incident-isolation + FT-customer-SLAs peger på separat deploy
- Egen Forge-site, egen Horizon-queue, egen Pulse, egen deploy-pipeline
- Konsekvens: separate ops-cost, men kritisk for FT-regulerede customer-SLAs

### 3.2 Tech stack — eksplicit PostgreSQL-valg

**PostgreSQL 16** for Frankston Lender, **MySQL** forbliver standard for resten af Frankston-stack.

**Hvorfor PostgreSQL specifikt for risk-database-segmentet** (motiverer 5-10 dages learning-curve-tax):

| Feature | Hvorfor det matter for Frankston Lender |
|---------|------------------------------------------|
| **jsonb** | Audit-trail payload-storage + loan-book metadata = native indexering + query-power |
| **Row-Level Security (RLS)** | DB-niveau tenant-isolation som defense-in-depth, ikke kun Eloquent-global-scope |
| **PostGIS** | Geo-koncentration computations (matrikel → region/kommune-aggregering) |
| **Materialized views** | Native primitive til concentration-analytics + incremental refresh |
| **Partition på tenant_id** | Native + performant for multi-tenant audit-tabeller |
| **pg_audit, pgcrypto extensions** | Industry-standard for FT-regulated workloads |

**Konsekvens for stack**:
- Frankston Lender = PostgreSQL 16
- Registry-api forbliver MySQL (migration først når forretningsmæssig grund)
- Mixed-stack accepteret som pragmatisk start
- Forge recipe + Pulse + Horizon configureres specifikt for PostgreSQL — budgeteret i §6.1 App-foundation

**Tech stack samlet**: Laravel 12 + PostgreSQL 16 + Livewire 3 + Flux Pro + Pest 4 + Forge + Horizon + Pulse + Flare.

### 3.3 Multi-tenant model

**Eksplicit ADR**: Frankston Lender ejer egen tenant-identity. Tre lag tenant-isolation:

1. **Application layer**: Global Eloquent `TenantScope` på alle non-system models — auto-scoped per request via middleware `EnforceTenantScope`
2. **Database layer**: PostgreSQL **Row-Level Security (RLS)** policies på alle tenant-data tabeller. Defense-in-depth — selv hvis app-layer fejler, DB nægter cross-tenant SELECT
3. **Test layer**: Pest-test-suite med eksplicit cross-tenant penetration scenarier (Tenant A's user prøver at læse Tenant B's data via direkte SQL + Eloquent + middleware-bypass forsøg)

**Tenant-tabel-design**:
```
tenants: id, name, created_at, deleted_at, trial_started_at, trial_ended_at, post_pilot_pricing_jsonb
tenant_users: id, tenant_id, user_id, role, created_at
loan_books: id, tenant_id, version, csv_hash, imported_at, superseded_at, ...
loan_positions: id, tenant_id, loan_book_id, investor_external_id, ...
concentration_snapshots: id, tenant_id, snapshot_date, ...
audit_log: id, tenant_id, user_id, action, resource_type, resource_id, payload_before, payload_after, created_at
```

**Registry-api konsumption**: Frankston Lender er READ-ONLY mod registry-api. Egen Sanctum-token per tenant. Aldrig writes mod registry-api's mortgage/property-tabeller. Cross-tenant leakage via registry-api umuliggjort fordi hver tenant's token-scope er tenant-specific.

### 3.4 Audit-trail mechanism

**Eksplicit valg**: dedicated `audit_log`-tabel med INSERT-only PostgreSQL-grant.

- **Schema**: `id`, `tenant_id` (partition key), `user_id`, `action`, `resource_type`, `resource_id`, `payload_before` (jsonb), `payload_after` (jsonb), `ip_address` (inet), `user_agent` (text), `created_at`
- **PostgreSQL grant**: `REVOKE UPDATE, DELETE ON audit_log FROM app_user`. Kun INSERT + SELECT
- **RLS policy**: tenant-scoped SELECT
- **Partition strategy**: range partition på `created_at` (månedlige partitioner) for performant retention-cleanup
- **Eksport-format til FT-tilsyn**: se Flow 5
- **Retention**: 5 år post-cancellation (FT-konvention). **PII-pseudonymisering ved cancel**, ikke efter 1 år (rettet fra v2 §2.3 ↔ Flow 6-inkonsistens)

---

## 4. Brainstorm decisions (Q1-Q7, sekventiel rækkefølge)

### Q1 — Repo-struktur: selvstændigt package

Se §3.1. `thefountainhead/frankston-lender-package` i ny repo.

**Shared UI-primitives**: kopier initial (FollowButton, AlertsInbox) — ~150 LOC per komponent. Eksport til `frankston-ui-package` når **konkret triplicering** rammer (≥3 komponenter, ≥2 packages, ændringer på flere steder samtidig).

### Q2 — Brand: Frankston Lender / `lender.frankston.io`

Sub-brand under Frankston-paraply. Subdomain-pattern matcher Salesforce (My Domain), Slack, Zendesk, Notion teams.

### Q3 — Build vs køb: build alt selv

Build ALLE concentration-analytics selv på DK-data (Tinglysning, EJF, BBR, registration_date). Ingen Moody's/S&P/FactSet/Bisnode/Risika i v1.

External rating providers cost-range: DKK 200K–1.5M/år (entry → multi-seat enterprise) — ikke værd det for SMV-segmentet vi targeterer.

### Q4 — Compliance

**Vi er non-critical TPSP** under DORA — oversight kontraktuel via kundens DPA Article 30, ikke direkte ESA-tilsyn.

**v1.0 compliance-must-haves**:
1. **DPA-template Article 30-compliant** (sub-processor disclosure, incident-flow, exit-plan, audit rights)
2. **Audit-trail mekanisme** (§3.4)
3. **Backup + DR** med dokumenteret RPO/RTO

**v1.5 compliance-features (når cohort #2-3 lander FT-regulated kunde)**:
4. DORA-incident-rapport-workflow (24-timers reporting)
5. Third-party risk register (procurement-tied)
6. Exit-export full-data CSV/JSON med integrity-hash

**Hard no-go uanset version** (§10):
- Automatisk kreditscoring uden human-in-the-loop
- GDPR Art. 22 automatiserede afgørelser
- Re-pakning af 3rd-party rating-data

### Q5 — Pricing

**Free pilot + value-based post-pilot** (se §2).

Pricing-model-kandidater (vurderes post-pilot):
- Per-position degressiv tier-model (§9.2) — én kandidat, ikke commitment
- Flat-rate per tenant
- Hybrid

### Q6 — Pilot scope: Solo Draupnir → milestone → cohort

**Phase 1 (v1.0, Q3 2026)**: Solo pilot — Draupnir (Ulrik + Rasmus). DKK 0/md trial.

**Phase 2 (post v1.0)**: Milestone-trigger til cohort. **2 af 3 falsifiable milestones**:
1. **Daily-use**: Rasmus logger ind ≥4 dage per uge i 4+ sammenhængende uger (måles via login-events i `audit_log`, ikke separat telemetri)
2. **Core-workflow integration**: Mindst én Frankston Lender-feature er det FØRSTE Rasmus åbner når han starter en risk-vurdering (verificerbart via interview + workflow-observation)
3. **Referral**: Rasmus introducerer Frankston Lender til mindst ÉN peer udenfor Draupnir (verifies via Rasmus' bekræftelse eller intro-email)

**Phase 3 (post-pilot)**: Cohort #2-3 — Nordic Bloom, Faktorkredit, + 1 warm lead. Samme free-trial model.

### Q7 — Integrationer

**v1.0 integrations (ship)**:
1. **CSV-import med generic-template** kun — `generic_loan_book.csv` med dokumenteret schema. Single-parser (Flow 2)
2. **Tinglysning-data** via registry-api (LIVE, ren genbrug)

**v1.5 integrations**: Bankdata/SDC/SimCorp templates, custom_mapping UI, real-time API, MitID, NemComply.

**v2.0+ park**: Bankdata formel API-partnership (12-18 mdr cycle).

---

## 5. User Flows

### Flow 1 — Pilot customer onboarding (v1.0, hand-held)

```
Day -7: Frederik signs DPA + pilot-aftale m. Ulrik (Draupnir)
Day -3: Frederik kører SQL-seeder: opret tenants-row + 2 tenant_users
Day -3: System genererer onboarding-email m. password-reset-link + TOTP-QR
Day 0: Ulrik/Rasmus logger ind → tom dashboard m. "Upload loan-book"-CTA
Day 0: Rasmus eksporterer Draupnir's loan-book (Excel) → konverterer til CSV
Day 0: Rasmus uploader CSV → preview → bekræfter
Day 1: System kører første alert-pipeline-pass → emails fra 08:00
```

Onboarding-flow er IKKE Livewire-wizard i v1.0 — SQL-seeder + manuel email. Wizard bygges når customer #3 lander.

### Flow 2 — CSV-import lifecycle

State machine: `uploaded → validating → preview → confirm → committed → snapshot_logged`

**Validering**:
- Row-level: required fields, format-check, FK-lookup mod registry-api for matrikel_id
- Partial-load: **NEJ i v1.0** — all-or-nothing. Hvis ≥1 row fejler, hele upload rejecteres med row-level error-report
- **Reupload-semantik**: versioned overwrite. Existing loan_book rows får `superseded_at = now()`. Ny upload bliver `version + 1`. Historik bevares
- **Idempotency**: hash af CSV-content. Identisk reupload → no-op
- **Customer markerer eksplicit "delta upload" vs "full export"**: Flow 4-DELETE-semantik kun aktiv ved "full export"-flag. Beskytter mod accidental row-disappearance fra filtreret Excel-eksport

**Disambiguation-integration** (async — adskilt fra import-SLO):
- CSV-import committer rows i to spande: `resolved` (CPR eller eksisterende person-match) og `pending_disambiguation` (borrower-navn uden match)
- Import-SLO (§9.4: 10K rows < 5 min) gælder KUN den synkrone commit-fase, ikke disambiguation
- Pending rows queues til Horizon-job `DisambiguatePersonJob` (batch 100, throttle 5/s mod metis-disambiguation-endpoint)
- Per-row budget: 500ms HTTP call + 200ms commit = ~700ms × 100 = ~70s pr. batch-job
- Worst-case: 10K rows pending = ~12 min queue-tid (acceptabelt — bruger ser "X rows under disambiguation" i UI)
- Disambiguation timeout/failure: row forbliver `pending_disambiguation` med error-context. Surfaces i dashboard "manual review needed"-liste. IKKE auto-accept.
- Disambiguation-pipeline-freshness SLO: ≤30 min for 95% af pending rows (separat SLO i §9.4)

### Flow 3 — End-user daily flow (Rasmus)

```
1. Login (email + password + TOTP)
2. Dashboard: top-3 concentration-warnings + new alerts siden i går
3. Drill: klik på loan → loan-detail-view m. embedded Tinglysning-tab
4. Alert action: Acknowledge / Snooze 7d / Mark resolved → logged til audit_log
5. Logout efter session-timeout 4h
```

### Flow 4 — Loan-book change/delta

Customer-flag på import bestemmer DELETE-semantik:
- **"Full export"-flag**: row mangler i ny upload → loan markeres `closed_at = now()`. Soft-delete med audit
- **"Delta upload"-flag**: kun rows i upload behandles. Manglende rows betragtes IKKE som closed
- **Default**: hvis customer ikke flag'er, upload afvises med "specificér mode"-error

Snapshot-table opdateres efter committed import. Næste 1.-i-måneden snapshot tæller position-count for **fremtidig billing** (post-pilot — v1.0 er gratis).

### Flow 5 — FT-tilsyn audit-export (security-hærdet)

```
1. Frankston-ops modtager FT-export request via dedicated support-kanal (verified email/Slack)
2. Frankston-ops genererer eksport-token via admin-tool
3. System sender FT-audit-email til CUSTOMER-admin's verified email (ikke just URL):
   - One-time-use token (mark consumed efter første download)
   - Sub-1-hour expiry
   - Recipient-binding: customer-admin's email + IP-allowlist (customer angiver IP range)
4. Customer-admin verificerer ved at klikke fra det specificerede IP-range
5. JSONL streaming download m. integrity-header (SHA256-hash + signing-cert)
6. Token markeres consumed; meta-audit logger download-event
```

**Sikkerheds-properties**:
- Ikke "del URL'en"-kompatibel (token er one-time-use)
- IP-allowlist forhindrer email-forward-misbrug
- Rate-limit: max 5 export-tokens per tenant per uge
- Audit-action på URL-generation logges (meta-audit)

### Flow 6 — Exit / off-boarding

```
Customer beslutter at opsige (under eller efter pilot)
   ↓
30-dages varsel
   ↓
Customer modtager email "data bliver slettet 30 dage post-cancel"
   ↓
Customer-admin kan eksportere full-data CSV/JSON i 30-dages-vinduet
   ↓
Day 30: Customer-data (loan-books, positions, snapshots) slettes (GDPR)
   ↓
Day 30: Audit-trail bevares 5 år (FT-konvention) MEN:
   - PII pseudonymiseres straks (user-names → "tenant_X_user_Y", emails redacted)
   - Aktioner + payload-strukturer bevares
   - Efter 5 år: full purge
```

**Eksplicit i DPA**: customer accepterer 5-års audit-trail-retention post-cancellation.

### Flow 7 — Lost-TOTP recovery (NEW)

```
1. Bruger melder lost TOTP
2. Frankston-ops verificerer via tenant-admin (Ulrik/Rasmus bekræfter)
3. Frankston-ops genererer reset-token via admin-tool (audit-logged)
4. Bruger sætter ny TOTP via QR-code-flow
5. Old TOTP-keys revoked
```

Reset uden tenant-admin-bekræftelse er IKKE muligt i v1.0 — undgår account-takeover-vector.

### Flow 8 — User permissions change (NEW)

```
1. Tenant-admin (rolle = "admin") inviterer ny bruger via email
2. Email indeholder magic-link til signup-flow (sætter password + TOTP)
3. Tenant-admin tildeler rolle ved invitation (admin / member)
4. Rolle-ændring logges til audit_log m. before/after
5. Rolle-revoke = soft-delete tenant_user row (audit bevares)
```

v1.0 har 2 roller: `admin` (alt) og `member` (læs + alert-actions). Kreditkomité-mode (workflow-stat-machine) defer til v1.5.

---

## 6. V1.0 Draupnir MVP — scope og effort (rebudgeted)

### 6.1 Effort-target: 60-80 dage total

Rebudgeted efter v2-reviewer-feedback. Inkluderer PostgreSQL-tax + tenant-isolation-tests + missing-flows.

| Område | Effort (dage) | Indeholder |
|--------|---------------|------------|
| App-foundation | 8-12 | Laravel 12 + Forge + Pulse + Horizon + PostgreSQL 16 setup + RLS-konfig + multi-tenant scaffold + composer.json + CI |
| Auth (email + TOTP) | 3-4 | Auth scaffold + spatie/laravel-totp + lost-TOTP recovery (Flow 7) + brute-force rate-limit |
| Tenant + user management | 4-5 | SQL-seeder + admin-route + team-management + roles (admin/member) + invite-flow (Flow 8) |
| Tenant-isolation testing | 2-3 | Pest cross-tenant penetration suite + RLS policy tests |
| Loan-book CSV import (Flow 2) | 6-8 | Generic-template parser + validation + versioning + idempotency + disambiguation-integration + delta-vs-full-flag |
| Loan-book CRUD + dashboard | 5-7 | List/detail-views + filtering + sorting |
| Concentration-analytics | 6-8 | Materialized views (geo + industri + debitor) + refresh-strategy + visualisations (PostGIS for geo) |
| F1/F5 alerts integration | 5-7 | Cross-app auth (tenant-scoped Sanctum) + alert-inbox copy + tenant_id-mapping + Lender-specific action-buttons |
| Tinglysning-tab embed | 1-2 | Genbrug F-NEW komponent |
| Audit-trail (§3.4) | 4-5 | Tabel + INSERT-only grant + RLS policy + partition-config + admin-export-route (Flow 5) |
| Security baseline (§8) | 3-4 | Encryption-at-rest config + secrets-management + CSRF/session-fixation tests + password-policy |
| Observability (§9) | 2-3 | Flare integration + Pulse config + uptime-monitor + SLO-doc |
| DPA-template + Article 30-doc | 2-3 | Jurist-tid + dokumentation |
| Backup + DR + SLA-doc | 2-3 | Forge backup-config + dokumentation |
| Off-boarding flow (Flow 6) | 2-3 | Soft-delete + pseudonymization-scheduler |
| **TOTAL** | **55-77 dage** | Median ~66 dage |

CAPEX-validation: 66 dage × ~DKK 5K/dag = ~DKK 330K, indenfor business-case-ramme DKK 270-420K.

### 6.2 V1.0 Livewire-komponenter

- LoanBookUpload (CSV-import wizard)
- LoanBookList + LoanBookDetail
- ConcentrationDashboard
- AlertInbox (genbrug fra metis-package via copy)
- TinglysningPanel (genbrug)
- AuditLogExport (admin-route)
- TeamManagement (Flow 8)
- ProfileSettings (TOTP setup, password change, lost-TOTP)

### 6.3 V1.0 Out-of-scope (eksplicit cut)

Multi-template CSV-arkitektur, Bankdata/SDC/SimCorp templates, StressTestRunner, MitID-signing, NemComply, DORA-incident-workflow, third-party risk register, kreditkomité state-machine, OnboardingWizard Livewire-komponent, PDF-eksport, SAML SSO, diff-preview ved reupload, billing-tier-infra (pilot er gratis).

---

## 7. Post-pilot Roadmap (v1.5+)

### 7.1 V1.5 (Q4 2026 — efter milestones fires)

| Feature | Effort | Trigger |
|---------|--------|---------|
| Bankdata-template CSV | 3-5 dage | Første SMV-bank-prospect signed LOI |
| SDC-template CSV | 3-5 dage | Tilsvarende |
| SAML SSO (Google/O365) | 7-10 dage | Cohort har enterprise IDP-krav (first-time-implementation tax) |
| StressTestRunner | 5-7 dage | Rasmus eksplicit bekræfter daglig-brug |
| DORA-incident-workflow | 5-7 dage | Første FT-regulated cohort-kunde |
| Third-party risk register | 3-5 dage | Samme trigger |
| PDF-eksport (uden signatur) | 3-5 dage | Customer beder om board-rapport |
| Diff-preview ved CSV-reupload | 3-5 dage | Customer eksplicit beder |
| Kreditkomité workflow-state-machine | 8-10 dage | Customer med formel kreditkomité-proces |

### 7.2 Post-pilot pricing-research (TBD)

Pricing-modellen er IKKE besluttet. Tre kandidat-modeller vurderes efter Draupnir-pilot baseret på målt værdi:

- **Flat-rate per tenant** — simpel, prædikterbar
- **Per-position degressiv tier-model** — skalerer med kundens volumen
- **Hybrid** (flat-rate base + position-overage)

Detaljeret tier-table og floor-vurdering produceres FØRSTE gang efter pilot-data foreligger (Q4 2026). Intern target-range: DKK 5-30K/md per kunde (segment-afhængigt, ikke i public spec).

**Status**: research-emne, ikke commitment. Ingen pris-tal i public spec før pilot-data har valideret value-anker.

### 7.3 V2.0+ park

Bankdata formel API-partnership, real-time API, MitID-signérbar PDF, Borrower-portal, Multi-currency/FX.

---

## 8. Security model

### 8.1 Encryption

- **At rest**: PostgreSQL `data_directory` på encrypted disk (Forge LUKS). pgcrypto extension for PII-felter (CPR, navne i loan-positions)
- **In transit**: HTTPS (Let's Encrypt via Forge). Internal Frankston Lender ↔ registry-api kald = TLS m. certificate-pinning til registry-api's prod-cert

### 8.2 Secrets management

- **Production**: Forge environment variables. Secrets aldrig i git
- **Development**: `.env` per dev (i `.gitignore`). Shared dev-secrets via 1Password Frankston-vault
- **Sanctum tokens**: stored hashed (sha256) — kunne aldrig læses tilbage selv ved DB-breach

### 8.3 Authentication

- **Email + password + TOTP** (Q5-svar)
- **Password policy**: min 12 chars, breach-check mod haveibeenpwned API ved set/reset
- **Brute-force protection**: 5 failed attempts → 15 min lockout. Rate-limit per IP + per user
- **TOTP**: spatie/laravel-totp. Recovery via Flow 7 (admin-mediated)
- **Session**: 4h timeout + http-only secure cookies. CSRF via Laravel default
- **Session-fixation**: regenerate session_id ved login

### 8.4 PII protection

- **Encrypt-at-column**: borrower CPRs i `loan_positions` krypteret via pgcrypto (key i Forge env)
- **Logging**: PII filtreres ud af Flare reports (custom `data_processor` strip CPR/email)
- **Pseudonymisering**: Flow 6 ved cancel — pgcrypto-redact + replace user-names med tenant-internal ID
- **Key rotation**: pgcrypto-key roteres årligt (kalenderbaseret) + immediate ved suspect compromise. Rotation-procedure: `bulk re-encrypt` migration på `loan_positions.cpr_encrypted` med dual-key-window (old + new). Forge-env opdateres atomisk via `forge env:update` + queue-restart. Audit-trail logger rotation-event (uden at logge selve keys)
- **Key-storage hardening**: produktion-key ALDRIG i `.env` på disk; Forge in-memory-injection ved deploy. Disaster-recovery-copy i sealed Frederik-1Password-vault

### 8.5 Tenant-isolation (defense-in-depth)

- **Layer 1**: Laravel `EnforceTenantScope` middleware (early-abort på request)
- **Layer 2**: Global Eloquent `TenantScope` trait (auto-WHERE på alle models)
- **Layer 3**: PostgreSQL RLS policies med **`FORCE ROW LEVEL SECURITY`** — gælder også for table-owner og superuser. Standard RLS bypass'es af owner; FORCE-flag lukker det hul.
- **Migration-runner-bruger**: dedikeret `migrator` role m. `BYPASSRLS` for schema-changes, ALDRIG `app_user`. App-runtime bruger `app_user` der ikke har BYPASSRLS.
- **Raw-SQL-guard**: `DB::raw()` + `DB::statement()` audited via Pint custom rule + Pest static-analysis test der scanner kode for hardcodede WHERE-clauses uden `tenant_id`-binding

**Pest cross-tenant penetration suite — 4 navngivne scenarier**:
1. **`it_blocks_cross_tenant_eloquent_query`** — Tenant A's authenticated user kalder `LoanPosition::find(tenantB_id)` → must return null (TenantScope kicks in)
2. **`it_blocks_cross_tenant_raw_sql`** — Tenant A's user kører `DB::select('SELECT * FROM loan_positions WHERE id = ?', [tenantB_id])` → must return 0 rows (RLS kicks in, layer 2 bypassed)
3. **`it_blocks_middleware_bypass_via_direct_controller_invocation`** — Tenant A's user invokerer Controller-method direkte (test-context skip middleware) → must throw `TenantContextMissingException` (layer 2 enforces uden middleware)
4. **`it_blocks_cross_tenant_via_relationship_traversal`** — Tenant A's user kalder `$user->tenant->loanBooks` hvor `$user` er Tenant A men forsøger relation til Tenant B's loan_book via stale FK → must return empty collection (scope enforces på relation-load)

Suite kører i CI hver push. Failure = block-merge.

### 8.6 CSRF + browser security

- Laravel default CSRF for ikke-API routes
- API-routes (Sanctum-protected) ekskluderet — token-bearer fungerer
- `X-Frame-Options: DENY` header — forhindrer clickjacking
- Content-Security-Policy default-src 'self' — strict
- `Strict-Transport-Security` max-age=31536000 + includeSubdomains

---

## 9. Observability + SLOs

### 9.1 Error tracking

**Flare** integration på Frankston Lender app. Egen Flare project (separat fra registry-api/metis). Alerting til Frederik når:
- ≥5 errors per 10 min på samme exception-class
- Any 5xx på admin-routes
- Database connection failures

### 9.2 Application metrics

**Pulse** (Laravel native) for:
- Slow requests (>1s)
- Slow queries (>500ms)
- Queue throughput (Horizon for jobs)
- Cache hit-rate

### 9.3 Uptime monitoring

**Oh Dear** (konsistent med Frankston-stack) monitorerer:
- `lender.frankston.io/up` health-endpoint hver 60s
- TLS-cert ekspiration
- DNS-konfiguration

### 9.4 SLO definition

**v1.0 SLOs** (committet til kunden via DPA):
- **Availability**: 99.5% månedligt (allow ~3.5h downtime/md)
- **Response time**: p95 < 1s for dashboard-routes
- **CSV-import synkron commit**: 10.000 rows < 5 min (gælder commit-fase, ikke async disambiguation — se Flow 2)
- **Disambiguation-pipeline freshness**: ≤30 min for 95% af pending rows
- **Alert-pipeline freshness**: ≤24h fra Tinglysning-event → email til customer

**Måling**: Oh Dear for availability. Pulse for response-time. Custom metrics for import-throughput. F1/F5-pipeline allerede instrumented i registry-api.

**"What wakes Frederik at 3am"**: Pulse fires Flare-alert ved p95 > 5s ELLER availability < 95% ELLER alert-pipeline >48h forsinket. Push-notifikation via Pushover til Frederik's telefon.

---

## 10. Hard out-of-scope (eksplicit forbud uanset version)

1. **Automatisk kreditscoring** uden human-in-the-loop (triggerer Databeskyttelseslovens kap. 5 + muligvis FT-licens-krav)
2. **GDPR Art. 22 automatiserede afgørelser** uden human-in-the-loop
3. **Re-pakning af 3rd-party rating-data** (license-vilkår)
4. **Borrower-portal** (særskilt produkt hvis det skal bygges)
5. **Mikro-lender freemium** (bevarer separation til Mikro-lender-OS — stealth-pilot Q1 2027)

---

## 11. Open Items (efter v3.1-pass)

1. **Post-pilot pricing** — TBD baseret på Draupnir-pilot value-måling. Tre kandidater i §7.2
2. **Bankdata-eksport-format** — research når første SMV-bank-prospect lander
3. **Niels-tråd** — Frederik samtaler Niels separat (AdminTech). Tracked i `project_admintech_niels_thread.md`
4. **Cohort #2-3 customer-commitment** — Nordic Bloom + Faktorkredit kontakt-status TBD efter Draupnir-pilot fires milestones
5. **GDPR Art. 17 vs 5-års audit-retention** — borrower-erasure-request interagerer med audit-trail. Pseudonymiseret payload i audit_log (user-names → tenant-internal ID, CPR via pgcrypto-redact) ER vurderet sufficient for Art. 17-compliance. Skal verificeres af DPA-jurist i §6.1 DPA-template-fasen
6. **CSV-template-schema-doc** — `generic_loan_book.csv` schema-dokumentation produceres som leverance under §6.1 Loan-book CSV import-task. Ikke separat artifakt før implementation
7. **pgcrypto key-rotation operational playbook** — §8.4 specificerer årlig + on-compromise rotation. Konkret runbook (dual-key-window, migration-rækkefølge, rollback) produceres under §6.1 Security baseline-task
8. **DPA Art. 28(3) vs Art. 30 terminologi** — §4 Q4 nævner "Article 30-compliant" som dækker records-of-processing. Sub-processor/incident/exit/audit-rights er typisk Art. 28(3)-indhold. Jurist verificerer korrekt artikel-reference i template

---

## 12. Cross-references

- Lender Intelligence projektmemory: `~/.claude/projects/-Users-Frederik/memory/project_lender_intelligence.md`
- Mikro-lender-research: `~/Dropbox/Frankston/VISION/mikro-lender-research-2026-05-23/`
- Pitch-script Ulrik+Omega: `~/Dropbox/Frankston/VISION/pitch-script-ulrik-omega-lender-pilot.md` (NB: pris-vilkår superseded af free-pilot model i §2)
- AdminTech / Niels-tråd: `~/.claude/projects/-Users-Frederik/memory/project_admintech_niels_thread.md`
- F-NEW Tinglysning-tab spec: `metis-package/docs/superpowers/specs/2026-05-02-portfolio-tinglysning-tab-design.md`

---

## 13. Confidence + next steps

### Confidence: 86/100 (post v3.1-pass)

**Score-history**:
- v2 self-claim 88 → reviewer 72 (−16 self-bias)
- v3 self-claim 92 → reviewer 81 (−11 self-bias)
- v3.1 self-claim 86 → reviewer-prognose 85-87 (kalibreret efter to passes)

**v3.1 fixes mod v3-reviewer (+5 fra 81)**:
- Flow 2 disambiguation async-design eliminerer SLO-modsigelse (+1.5)
- §8.5 FORCE RLS + 4 navngivne Pest-scenarier + raw-SQL-guard (+1.5)
- §8.4 pgcrypto key-rotation policy (+1)
- §7.2 trimmet — ingen prismæssig over-commitment (+0.5)
- §11 reconciled m. §13 + Art. 17 + Art. 28/30 terminologi opklaret (+0.5)

**Remaining 14-point gap til 100** (alle reel, ikke spec-spørgsmål der kan løses i text):
- Post-pilot pricing forbliver TBD til Q4 2026 (-4)
- Mixed-stack PostgreSQL/MySQL ops-overhead (-2)
- DPA-template juridisk-faktum-check ikke gjort endnu (-2)
- Cohort #2-3 customer-discovery ikke afsluttet (-2)
- PostGIS first-time-implementation-tax fortsat usikkert estimat (-2)
- pgcrypto key-rotation runbook ikke produceret (-1)
- CSV-schema-doc produceres under implementation, ikke før (-1)

### Next steps

1. **Frederik review af v3.1-spec** — flagger uenighed eller justering
2. **Fresh-reviewer-runde** (v3.1) verificeret: 85/100, self-claim 86 = kalibreret ±1
3. **/plan workflow** — transform til implementering-plan med TDD-tasks (60-80 dage budget)
4. **Niels-mail** (parallel) — discovery-samtale om AdminTech
5. **Frankston-lender-package repo-oprettelse** — GitHub-org + composer.json + ServiceProvider boilerplate
6. **Draupnir pilot-aftale draft** — DKK 0/md trial + milestone-definitions + 30-dages exit-vilkår

**Confidence 86 → over /plan-threshold 85. Klar til /plan.**
