---
module: Metis
date: 2026-08-08
problem_type: integration_issue
component: service_object
symptoms:
  - 'HTTP request returned status code 503: {"message": "Service Unavailable"} (Flare 9104992, 56 forekomster paa 9 dage)'
  - "Sektioner paa /lookup/cvr/{cvr} viste tom tilstand ('0 aktieposter', tom portefoelje) under registry-api-deploys"
  - "UptimeRobot 503-alarm paa registry-api 7/8 14:52-14:57 UTC faldt sammen med spike i rescue-rapporter"
root_cause: logic_error
laravel_version: 12.58.0
resolution_type: code_fix
severity: medium
tags: [retry, http-503, maintenance-mode, http-client, registry-api, deploy-window, fail-fast, false-denial]
---

# Troubleshooting: 503 under registry-api-deploys fik sektioner til at lyve "ingen data"

## Problem

Registry-api quick-deployer ved merge til main, og deploy-scriptet svarer 503 (Laravel
maintenance mode) mens `migrate` koerer — typisk sekunder, men op til ~4 minutter ved tunge
migrationer (7/8-26: `CONCURRENTLY`-indeksbyg paa 83,8 mio. raekker, registry-api PR #250).
Metis-klienten behandlede 503 som ethvert andet 5xx-svar: ingen retry, kaldet faldt til sin
fallback, og sektionen viste tom tilstand — en falsk autoritativ benaegtelse ("0 aktieposter")
midt i due diligence.

## Environment

- Module: Metis (metis-package, `src/Services/RegistryApi.php`)
- Laravel Version: 12.58.0
- Affected Component: `RegistryApi::client()` — den delte HTTP-klient bag alle registry-api-kald
- Date: 2026-08-08 (incident diagnosticeret 7/8, fix live 8/8)

## Symptoms

- Flare 9104992: `Illuminate\Http\Client\RequestException` "HTTP request returned status code
  503" — 56 rescue-rapporter 29/7–7/8, entry point Livewire lazy-sections paa `/lookup/cvr/{cvr}`
- Sektioner renderede tomme (ikke fejlside — `rescue()`/try-catch fangede og faldt til fallback)
- Moenstret korrelerede 1:1 med registry-api-deploys (git-reflog paa serveren vs. Flare-tidsstempler)

## What Didn't Work

**Antagelse 1: "Det er et udfald/crash paa registry-api"**
- **Why it failed:** Nginx-audit-loggen (`/var/log/nginx/frankston-audit/<host>.access.log`)
  viste 503-svar serveret paa 0,007–0,02s fra FPM — det er Laravel maintenance mode, ikke en
  crashet app. Registry-api's egen Flare var ren, load lav (0,89), FPM koerte uafbrudt.

**Antagelse 2: "Klienten har allerede retry"**
- **Why it failed:** `client()` havde retry — men when-callback'en matchede KUN
  `ConnectionException`. En 503 er et *modtaget svar*, ikke en transportfejl, saa den gik
  udenom. Designet var bevidst ("aldrig retry paa modtaget 4xx/5xx") men overså at 503-under-
  deploy semantisk er "proev igen om lidt", ikke et svar paa spoergsmaalet.

## Solution

To retries med backoff paa 503 i `client()`'s when-callback, plus fail-fast naar et langt
vindue er konstateret. Transport-retryen (1 retry, kun `ConnectionException`) er uaendret —
taelle-logikken holder de to fejlklasser adskilt i samme callback.

```php
// Before (broken): 503 gik udenom retry og faldt direkte til fallback
->retry(2, 2000, fn (\Exception $e) => $e instanceof ConnectionException, throw: false)

// After (fixed): 503 retries 2s+5s; ved udtoemte retries saettes instans-flag
// saa flerkalds-stier ikke betaler 7s sleep pr. delkald gennem et langt vindue
$transportRetries = 0;
$maintenanceRetries = 0;

->retry([2000, 5000], 0, function (\Exception $e) use (&$transportRetries, &$maintenanceRetries) {
    if ($e instanceof ConnectionException) {
        return ++$transportRetries <= 1;   // uaendret: 2 totale forsoeg
    }

    if ($e instanceof RequestException && $e->response->status() === 503) {
        if ($this->maintenanceObserved) {
            return false;                   // kendt langt vindue: straks til fallback
        }
        if (++$maintenanceRetries >= 2) {
            $this->maintenanceObserved = true;
        }
        return $maintenanceRetries <= 2;
    }

    return false;
}, throw: false)
```

Framework-mekanik verificeret i Laravel 12.58 `PendingRequest`: when-callback'en kaldes med
`RequestException` for hver fejl-status ogsaa med `throw: false`, og ved udtoemte retries
returneres respons-objektet — alle kaldesteder (med og uden `->throw()`) opfoerer sig som foer
ved endelig fiasko. Leveret i PR #150 (`c739365`), app-bump TheFountainhead/metis#54.

## Why This Works

- **2s+5s daekker et normalt migrate-vindue** (sekunder) — korte deploys bliver usynlige.
- **Fail-fast-flaget** (`maintenanceObserved`, instans-state) beskytter flerkalds-stier som
  person-soegningens per-person property-counts mod at gange 7s ventetid op med antal delkald
  gennem et langt vindue (fx 4-min indeksbyg). Instans doer med requestet under FPM; maa IKKE
  flyttes til singleton uden reset hvis appen en dag koerer Octane.
- **Eksisterende fallbacks uroerte:** ved endelig 503 opfoerer alt sig praecis som foer fixet.

## Verification

- Fuld suite 619 passed; mutations-matrix: 503-gren deaktiveret → alle 3 nye tests roede;
  flag saettes aldrig → praecis fail-fast-testen roed (begge mutationer reverteret)
- Deploy verificeret paa serveren (release 75041028, composer.lock paa `c739365`) + UI i
  browser paa CVR 30694872 — sagen fra Flare-fejlen
- Facitliste: Flare 9104992 er snoozet til 12/8 — nye forekomster efter 8/8 betyder at fixet
  ikke virker som antaget

## Prevention

- **503 fra en intern API under deploy er "proev igen", ikke et svar.** Naar en klient-politik
  siger "aldrig retry paa modtaget status", overvej 503-undtagelsen eksplicit — især mod
  services med quick-deploy og maintenance mode i deploy-scriptet.
- **En hurtig 503 (< 0,1s fra FPM) er maintenance mode**, ikke crash/overbelastning — tjek
  git-reflog paa target-serveren foer der raabes udfald.
- **Tom-tilstand som fallback for en transient fejl er en falsk benaegtelse.** Fravaer af data
  og "kunne ikke hente data" er forskellige udsagn; vis aldrig det foerste naar sandheden er
  det andet.

## Related Issues

- Registry-api-siden af haendelsen: `~/.claude/projects/-Users-Frederik/memory/compound_registry_api_deploy_503_index_migration_2026_08_07.md`
- Transport-haerdningen der byggede retry-fundamentet: Flare 9097433 (27/7-26), dokumenteret i `client()`-kommentaren
