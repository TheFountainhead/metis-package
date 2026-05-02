# Commercial-rents pipeline status (lokal kode-audit, 2. maj 2026)

**Formål:** Verificér eksisterende lejedata-pipeline i `registry-api` for F8 (lejeniveau med kilde-mærkning). Frederik nævnte at vi tidligere har trukket data fra Ejendomstorvet — bekræft om det stadig kører.

**Metode:** Lokal kode-audit kun (ingen SSH til Forge, ingen prod-DB queries). Verificeret hvad der findes i koden + scheduler-konfig.

## Hvad findes i registry-api

### Erhvervsleje (Ejendomstorvet)

| Komponent | Status | Detaljer |
|---|---|---|
| `app/Services/Scraping/EjendomstorvetClient.php` | ✅ EKSISTERER (53 linjer) | Henter erhvervsleje via `https://www.ejendomstorvet.dk/api/v2/listings/search` |
| `app/Console/Commands/ImportCommercialRentsCommand.php` | ✅ EKSISTERER (~80 linjer) | Artisan-command `commercial-rents:import` med presets |
| `app/Models/CommercialRent.php` | ✅ EKSISTERER (22 linjer) | Eloquent model |
| `database/migrations/2026_03_01_300001_create_commercial_rents_table.php` | ✅ EKSISTERER | Schema klar |
| `app/Enums/CommercialPropertyType.php` | ✅ EKSISTERER | 5 typer: Office, Retail, Warehouse, Production, Other |
| **Scheduler i `routes/console.php`** | ❌ **MANGLER** | Command er ikke schedulet som cron — kører kun manuelt |

**Presets i ImportCommercialRentsCommand:**
- `copenhagen` (29 postnumre 1050-2500)
- `major` (~50 erhvervsbyer DK-vid)

**Property types fetched:**
- `kontor` (kontor)
- `butik` (detailhandel & butik)
- `lager` (lager)
- `produktion` (lager & produktion)
- `andet` (andre typer)

### Boligleje + boligsalg (Boliga)

| Komponent | Status |
|---|---|
| `app/Models/BoligaListing.php` | ✅ EKSISTERER |
| `app/Console/Commands/BoligaSyncListingsCommand.php` | ✅ EKSISTERER |
| `app/Console/Commands/BoligaSyncSoldCommand.php` | ✅ EKSISTERER |
| `app/Console/Commands/BoligaBulkSoldCommand.php` | ✅ EKSISTERER |
| **Scheduler i `routes/console.php`** | ✅ **AKTIV** |

**Boliga schedule (verificeret i routes/console.php):**
```
Schedule::command('boliga:sync-listings')->dailyAt('06:00');
Schedule::command('boliga:sync-sold')->dailyAt('07:00');
Schedule::command('boliga:bulk-sold')->monthlyOn(1, '01:00');
```

## Konklusion

**Boliga (boligleje + boligsalg):** ✅ Aktiv pipeline, daily 06:00 + 07:00.

**Ejendomstorvet (erhvervsleje):** ⚠️ **Pipeline EKSISTERER men er IKKE schedulet.** Code-base er færdig, men der er ingen cron-entry i `routes/console.php` for `commercial-rents:import`. Det betyder data kun importeres når Frederik/Kristian kører commandoen manuelt.

## Hvad skal verificeres mod prod-DB (mandag morgen)

Frederik bedes køre på Forge:

```sql
-- Hvor mange commercial_rents-rows har vi?
SELECT COUNT(*) FROM commercial_rents;
SELECT MAX(created_at) AS latest_import FROM commercial_rents;

-- Hvor mange boliga_listings?
SELECT COUNT(*) FROM boliga_listings;
SELECT MAX(synced_at) AS latest_sync FROM boliga_listings;
```

Eller via Artisan tinker mod prod (forsigtigt):
```bash
php artisan tinker --execute="echo App\Models\CommercialRent::count();"
php artisan tinker --execute="echo App\Models\BoligaListing::count();"
```

## Anbefaling for F8 i Sprint 4

Givet at Boliga er aktiv og Ejendomstorvet kun mangler scheduling, er F8 (lejeniveau med kilde-mærkning) ekstremt billig:

**Sprint 4 F8-aktivering (estimat 2-3 dage):**

1. **Dag 1: Aktivér Ejendomstorvet-scheduler** — tilføj 2 linjer i `routes/console.php`:
   ```php
   Schedule::command('commercial-rents:import --preset=major')
       ->weeklyOn(1, '08:00')
       ->withoutOverlapping()
       ->onOneServer();
   ```
   Plus initial backfill: `php artisan commercial-rents:import --preset=major` manuelt på Forge.

2. **Dag 2: Tilføj aggregat-endpoint** i `registry-api/routes/api/v2.php`:
   ```php
   Route::get('rent-levels/{postalCode}', [RentLevelController::class, 'show']);
   ```
   Returns: median + p25/p75 leje-pr-kvm pr postal_code + property_type + kilde ('ejendomstorvet_advert' / 'boliga_advert' / 'reported').

3. **Dag 3: Metis UI** — tilføj `RentLevelPanel`-komponent på adresseside + selskabsside (vis aggregat for postnummer + nærmeste lignende type).

4. **Kilde-badge på hver leje-værdi** — markedsmessig differentiator vs Resight ("Annonce-pris fra Ejendomstorvet, ikke faktisk lejekontrakt").

## Andre relevante kilder vi kan tilføje senere

| Kilde | Coverage | Prioritet |
|---|---|---|
| **Boligportal.dk** | ~80% af danske private boligudlejere | P1 (efter pilot-feedback) |
| **Findbolig.nu** | Almene boliger (DAB, KAB, fsb, Lejerbo) | P2 |
| **Lejebolig.dk** | Privatudlejere "long tail" | P2 |
| **bolig-online.dk** | Privatudlejere | P3 |
| **DBA.dk bolig** | Long-tail private | P3 |
| **GLR (Grundejernes Investeringsfond)** | Faktiske lejekontrakter (kommerciel) | P1 hvis adgang sikret |

GLR ville være den eneste *faktiske* (ikke annonce-baseret) kilde i Danmark — adgang er kommerciel og kræver afklaring.

## Konfidens: 85

Lokal kode-audit har bekræftet alt eksistens; prod-DB row-count + sidste import-dato kan jeg ikke verificere uden Forge-adgang. De 15% usikkerhed: er commercial_rents-tabellen tom (kommando aldrig kørt), delvist populeret (én manuel kørsel), eller surprisingly populeret (cron-config sat på Forge uden routes/console.php-entry)?
