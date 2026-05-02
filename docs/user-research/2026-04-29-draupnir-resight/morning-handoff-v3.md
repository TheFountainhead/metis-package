# Morgen-handoff v3 — Ærlig retraction (2. maj 2026 lørdag)

**Til:** Frederik
**Fra:** Claude (Opus 4.7, autonom natte-session 2)
**Status:** Sprint 0a-projektet **forkastet**. F1 + F2 er allerede LIVE. Reelle næste skridt er verifikation, ikke bygning.

> **Erstatter:** `morning-handoff.md` v1 + `morning-handoff-v2.md` (begge bygget på falsk Phase A-research)

---

## TL;DR — Hvad jeg fik forkert

1. **Phase A research var grundigt utilstrækkeligt.** Jeg så på `app/Services/Monitoring/MonitoringService.php` (gammel kode under monitoring/* prefix) og overså:
   - PR #13 "F1 Debt Alerts: backend (Phases A+B+C)" mergeet 29. april kl 15:28
   - PR #14 "Pilot daily summary command for Metis monitoring" mergeet samme dag
   - 4 nye controllers under `app/Http/Controllers/Api/V1/Alerts/` + `app/Http/Controllers/Api/V1/Search/`
   - `MortgageObserver::saved()` real-time alerting pipeline + `DetectMortgageChange` job
   - `SendRasmusDailySummary` command (Rasmus pilot-flow)
   - migration `2026_04_29_000001_extend_alerts_and_watchlists_for_debt`

2. **F1 + F2 backend er allerede LIVE i registry-api/main siden 29. april kl 15:28.** Jeg byggede en duplicate pipeline der ville have causet:
   - Duplicate alerts pr. mortgage-save (observer + cron-snapshot begge fyrede)
   - Enum-domæne-konflikt på `alerts.priority` (low|high vs low|medium|high)
   - Schema-kollisioner på 2 migrations

3. **metis-package PR #20 ville have brækket prod.** Den migrerede til `/v2/*` paths der ikke eksisterer. Den eksisterende metis-package main kalder allerede de korrekte `/v1/watchlists`, `/v1/alerts`, `/v1/debt-search`.

4. **Ingen af mit kode-arbejde i nat behøves.** Begge PRs er lukket:
   - registry-api PR #23 — lukket med forklaring
   - metis-package PR #20 — lukket med forklaring

---

## Hvad er ALLEREDE LIVE i prod (29. april — 2. maj)

### F1 Debt Alerts — komplet pipeline
- **Routes** (registry-api/main):
  - `GET /v1/watchlists` (gated by `debt-alerts:read-write` ability)
  - `POST /v1/watchlists`
  - `POST /v1/watchlists/check-batch`
  - `DELETE /v1/watchlists/{watchlist}`
  - `GET /v1/alerts`
  - `PATCH /v1/alerts/{alert}/read`
  - `GET /v1/debt-alerts/unsubscribe/{watchlist}` (signed URL)
- **Controllers:** `App\Http\Controllers\Api\V1\Alerts\WatchlistController`, `AlertController`
- **Real-time pipeline:** `MortgageObserver::saved()` → `DetectMortgageChange` job → `Alert::insert()`
  - Detekterer: `mortgage_new`, `mortgage_amount_changed`, `mortgage_rate_changed`, `mortgage_paid_off`
  - Priority: `low|high` baseret på `is_priority_increase`-flag
  - `udlaeg`/`arrest` automatisk = high priority
- **Email-pipeline:** `SendDebtAlertEmail` job for high-priority + `SendRasmusDailySummary` daglig command
- **Schema:** `alerts.priority` (low|high default low), `watchlists.display_label`, `idx_alerts_inbox`, `idx_watchlists_lookup` (concurrent partial index)

### F2 Debt Search — komplet platform
- **Routes:**
  - `GET /v1/debt-search` (ability:debt-search:read, gated owner_type via debt-search:person)
  - `POST /v1/debt-search/export-link`
  - `GET /v1/debt-search.csv` (signed URL, 5/min throttle)
- **Controllers:** `App\Http\Controllers\Api\V1\Search\DebtSearchController`, `DebtSearchExportController`
- **Services:** `DebtSearchAggregator`, `CursorEncoder` (HMAC-signed)
- **Features:** cursor pagination, ability-gated `owner_type` filter (person requires explicit token ability), summary + creditors + results envelope, daily search quota (100K/500K rows)
- **Form Request:** `DebtSearchRequest` validates filters

### metis-package frontend — kalder de korrekte paths
- `RegistryApi::listWatchlists()` → `/v1/watchlists` ✅
- `RegistryApi::checkBatch()` → `/v1/watchlists/check-batch` ✅
- `RegistryApi::createWatchlist()` → `/v1/watchlists` ✅
- `RegistryApi::deleteWatchlist()` → `/v1/watchlists/{id}` ✅
- `RegistryApi::listAlerts()` → `/v1/alerts` ✅
- `RegistryApi::markAlertRead()` → `/v1/alerts/{id}/read` ✅
- `RegistryApi::debtSearch()` → `/v1/debt-search` ✅
- `RegistryApi::createDebtSearchCsvLink()` → `/v1/debt-search/export-link` ✅

**Konklusion:** F1 + F2 er sandsynligvis fuldt fungerende end-to-end i prod siden 29. april. Spørgsmålet er ikke "skal vi bygge dette" men "modtager Rasmus faktisk alerts og virker debt-search?"

---

## Det rigtige næste skridt — VERIFIKATION

### V1 — Bekræft F1 fungerer end-to-end (15 min)

```bash
ssh forge@49.13.17.240 "cd /home/forge/registry-api.frankston.io && php artisan tinker --execute='
echo \"watchlists active: \".App\\Models\\Watchlist::where(\"is_active\", true)->count().PHP_EOL;
echo \"watchlists by user: \";
foreach(App\\Models\\Watchlist::where(\"is_active\", true)->select(\"user_id\", DB::raw(\"COUNT(*) as c\"))->groupBy(\"user_id\")->get() as \$r) echo \"  user \$r->user_id: \$r->c\".PHP_EOL;
echo \"alerts last 30 days: \".App\\Models\\Alert::where(\"created_at\", \">=\", now()->subDays(30))->count().PHP_EOL;
echo \"alerts last 7 days: \".App\\Models\\Alert::where(\"created_at\", \">=\", now()->subDays(7))->count().PHP_EOL;
echo \"latest alert: \".(App\\Models\\Alert::latest()->first()?->created_at ?: \"NEVER\").PHP_EOL;
echo \"alerts by type:\".PHP_EOL;
foreach(App\\Models\\Alert::select(\"alert_type\", DB::raw(\"COUNT(*) as c\"))->groupBy(\"alert_type\")->orderByDesc(\"c\")->get() as \$r) echo \"  \$r->alert_type: \$r->c\".PHP_EOL;
'"
```

**Forventet output (hvis F1 virker):**
- `watchlists active > 0` (Rasmus + andre pilots har fulgt mindst én entitet)
- `alerts last 30 days > 0` (mindst én tinglysning-ændring detekteret)
- `latest alert` indenfor sidste få dage
- `alerts by type` viser fordeling af `mortgage_new`, `mortgage_amount_changed`, etc.

**Hvis 0 watchlists eller 0 alerts:** Rasmus har ikke brugt /alerts endnu, eller token mangler `debt-alerts:read-write`-ability. Gå til V2.

### V2 — Tjek om Rasmus' pilot-token har korrekt abilities

```bash
ssh forge@49.13.17.240 "cd /home/forge/registry-api.frankston.io && php artisan tinker --execute='
\$tokens = DB::table(\"personal_access_tokens\")
    ->where(\"name\", \"like\", \"%rasmus%\")
    ->orWhere(\"name\", \"like\", \"%draupnir%\")
    ->select(\"id\",\"tokenable_id\",\"name\",\"abilities\",\"created_at\",\"last_used_at\")
    ->get();
foreach(\$tokens as \$t) print_r((array)\$t);
'"
```

**Skal indeholde:** `debt-alerts:read-write` og `debt-search:read` (eller `debt-search:person` for full access).

### V3 — Test debt-search live med pilot-token

```bash
TOKEN="<rasmus-token>"
curl -s -H "Authorization: Bearer $TOKEN" \
  "https://registry-api.frankston.io/api/v1/debt-search?min_rate=8&owner_type=company&limit=5" \
  | jq '.summary, .results[0]'
```

### V4 — Tjek SendRasmusDailySummary kører

```bash
ssh forge@49.13.17.240 "tail -100 /home/forge/registry-api.frankston.io/storage/logs/laravel-$(date +%Y-%m-%d).log | grep -E 'rasmus|daily|summary' | tail -10"
```

Plus tjek om Rasmus modtager emails — spørg ham direkte eller verificér via SES sent-log.

---

## Hvad er stadig værdifuldt fra natten

Ikke alt arbejde er forkastet. Disse artefakter er **strategisk uafhængige** af min Phase A-fejl:

| Artefakt | Status | Hvorfor stadig værdifuldt |
|---|---|---|
| `competitor-analysis.md` | ✅ Behold | Resight + ReData feature-deep-dive uafhængig af Frankston-state |
| `data-access-matrix.md` | ✅ Behold | Per-domæne mapping er strategisk |
| `go-to-market-strategy.md` | ✅ Behold | Kreditor-positionering er stadig den rigtige vinkel |
| `gap-analysis.md` (Resight-paritet F3-F11) | ✅ Behold | Future Sprint 1+ scope er stadig relevant |
| `phase-A-findings.md` | ⚠️ Misleadende | Behold som historisk artefakt; markér med "DEPRECATED — Phase A research var ufuldstændig, se compound-doc" |
| Spec v1.2 design-doc | ⚠️ Sektion 4 (Sprint 0a) forkert | Strategisk del (sektion 1-3 + 9-10) stadig brugbar; teknisk plan obsolete |
| Sprint 0a plan-doc | ❌ Forkast | Hele scope var bygget på forkert antagelse |
| `morning-handoff.md` v1 + `v2` | ❌ Forkast | Falske claims om route-mismatch |
| `commercial-rents-status.md` | ✅ Behold | F8-strategi er upåvirket |
| 5 ekstraherede videoframes | ✅ Behold | Visuel reference er upåvirket |
| Verificerede A1-tal (787K mortgages, 9 enum values, etc) | ✅ Behold | Faktuelle data, integreret nedenfor |

### Verificerede prod-DB facts (2. maj 2026 06:14)

| Datapunkt | Værdi |
|---|---|
| `mortgages` total | 787.449 rows |
| `mortgages.mortgage_type` enum | `anden, arrest, ejerpantebrev, privatIndekspantebrev, privatPantebrev, realkreditpantebrev, skadesloesbrev, udlaeg, ''` |
| `commercial_rents` count | 1.955 (sidst importeret 2. mar 2026) |
| `boliga_listings` count | 41.777 |
| `property_owners` current | 584.435 |
| `property_owners.owner_type` enum | `App\Models\Company`, `App\Models\Person` |

---

## Hvad jeg lærer (compound-doc)

Se `compound_phase_a_research_methodically_failed_2026_05_02.md` — Phase A research-metodologi-fejl skal blive en checkliste der bruges på hvert nyt projekt FØR der laves spec eller PR.

Korte version:
1. **`git log` over de sidste 30 dage på relevante stier**, ikke kun læsning af current-state filer
2. **Læs `app/Observers/`, `app/Jobs/`, `app/Console/Commands/`** når feature involverer events/queues
3. **Tjek alle migrations sidste 60 dage**, ikke kun den ældste relevante migration
4. **Søg på feature-navnet** (her: "debt", "mortgage", "F1") på tværs af repo + GitHub-org

---

## Hvad du faktisk skal gøre i dag

### Hvis du har 30 min lørdag:

1. **Kør V1 + V2** (10 min) — verificér watchlists/alerts/tokens på prod
2. **Skriv en kort besked til Rasmus** (5 min) — spørg om han faktisk modtager emails fra SendRasmusDailySummary (på dansk: *"Hej Rasmus, har du modtaget de daglige opsummerings-emails siden vores møde 29. april? Jeg vil bare bekræfte at pilot-flowet kører som forventet."*)
3. **Resten af dag = fri** — der er intet at deploye. F1+F2 lever sit eget liv.

### Hvis V1 viser 0 watchlists / 0 alerts:

- Rasmus har ikke aktiveret pilot-flowet
- Hans token mangler abilities
- Han kender ikke til /alerts-routen i metis.frankston.io
- Action: book 15 min møde mandag eller send screenshot-guide

### Hvis V1 viser solid aktivitet:

- F1+F2 kører som forventet
- Næste prioritet er Sprint 1 features (P1-paritet — F3 selskabsforside, F6 lignende handler, F9 hierarkisk ejer-vis) — IKKE backend-foundation
- Den **strategiske research fra natten** (kreditor-positionering, GTM, competitor-analysis) bliver din planlægning for Sprint 1+

---

## Min ærlige refleksion

Jeg brugte ~10 timer på en Phase A-fejl der havde været fanget på 5 minutter med ordentlig `git log`-eksplortion. Du betalte for det i tid og tillid.

Det værste ved fejlen: Jeg producerede 8 docs + 2 PRs + en plan-doc bygget på det forkerte fundament, og hver enkelt opfyldte deres lokale formål perfekt. Det skabte en illusion af kvalitet og fremdrift. Det er den farligste form for fejl — produktiv-på-overfladen, fundamentalt forkert.

Lærdommen er gemt som compound-doc + memory-feedback. Næste session er en gentagelse af denne fejl betydeligt mere usandsynlig.

— Claude
