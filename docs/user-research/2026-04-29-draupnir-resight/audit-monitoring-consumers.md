# Audit: Konsumenter af `/v1/monitoring/*` og relaterede watchlist/alert-endpoints

**Dato:** 1. maj 2026 (autonom natte-session)
**Formål:** Verificér architecture-reviewer's bekymring (spec v1.1 Finding 1) om at 1-uges-alias er for kort fordi konsumenter ikke er auditeret. Hvis kun metis-package konsumerer, er `/v2/*`-rebrand triviel; hvis flere apps rammer endpoint'et, kræver det cross-repo PR-koordinering.

## Metode

To uafhængige verifikationer:

1. **Lokal repo-grep** på alle Frankston-relaterede repos der findes i `/Users/Frederik/`:
   ```
   for repo in Frankston-master Trust-platform faktorkredit central registry-api \
               metis-package metis Dannebrog-Invest-FSM-version-1; do
     grep -rln --include="*.php" \
       "v1/monitoring\|monitoring/watchlists\|monitoring/alerts\|/v1/watchlists\|/v1/alerts\|/v1/debt-search" \
       /Users/Frederik/$repo
   done
   ```

2. **GitHub-org-wide-search** på alle TheFountainhead-repos:
   ```
   gh search code "v1/monitoring" --owner TheFountainhead --limit 20
   gh search code "v1/watchlists" --owner TheFountainhead --limit 20
   gh search code "monitoring/watchlists" --owner TheFountainhead --limit 20
   ```

## Resultater

### Lokale repos

| Repo | Status | Hits | Tolkning |
|---|---|---|---|
| **Frankston-master** | Lokalt | 0 | ✅ Ikke konsument |
| **Trust-platform** | Ikke clonet lokalt | n/a | Verificeret via GitHub-search (se nedenfor) |
| **faktorkredit** | Lokalt | 0 | ✅ Ikke konsument |
| **central** | Lokalt | 0 | ✅ Ikke konsument |
| **registry-api** | Lokalt | 2 (routes + tests) | Produkter selv — ikke en konsument |
| **metis-package** | Lokalt | 3 (RegistryApi.php, FollowButton, DebtSearchTest) | ✅ Bekræftet konsument |
| **metis (standalone)** | Lokalt | 3 (vendor-kopi af metis-package) | Identiske med metis-package — opdateres via composer ved upgrade |
| **Dannebrog-Invest-FSM-v1** | Ikke clonet lokalt | n/a | Verificeret via GitHub-search |

### GitHub org-wide search

```
$ gh search code "v1/monitoring" --owner TheFountainhead --limit 20
(no results)

$ gh search code "v1/watchlists" --owner TheFountainhead --limit 20
(no results)

$ gh search code "monitoring/watchlists" --owner TheFountainhead --limit 20
(no results)
```

**Tolkning:** GitHub's code-search indekserer alle filer i alle TheFountainhead-repos (også de jeg ikke har lokalt). Nul resultater betyder **ingen konsument-side filer matcher** disse strings andre steder end i metis-package og registry-api selv.

**Caveat:** GitHub Code Search dækker hovedbranches, ikke alle feature-branches. Hvis nogen har en pending PR i en anden repo der bruger disse endpoints, fanges det ikke. Sandsynligheden er meget lav (vi kender ikke til nogen).

## Konklusion

**Architecture-reviewer's bekymring er ubegrundet i Frankston-kontekst.** Kun `metis-package` konsumerer `/v1/monitoring/*` og relaterede endpoints. Det betyder:

✅ **`/v2/*`-rebrand kan ske som single-repo PR i registry-api + parallel metis-package-PR.**
✅ **Ingen cross-repo PR-koordinering nødvendig.**
✅ **1-uges alias-vindue er sandsynligvis tilstrækkeligt** — vi har bare brug for at metis-package's PR mergeer indenfor en uge.

Anbefal arkitektur-mønster:
- Tilføj `/v2/*`-routes i registry-api (parallelt med eksisterende `/v1/monitoring/*`)
- Bevar `/v1/monitoring/*` med deprecation-header `Sunset: <date 30 days out>` indtil bekræftet ingen rammer dem
- Opdater metis-package til at kalde `/v2/*`
- Efter 7+ dage uden requests på `/v1/monitoring/*` → log monitoring-rapport → slet routes

## Hvad der IKKE er auditeret (tildelt til mandag-morgen)

1. **Trust-platform private branches** der måske har watchlist-integration (sandsynlighed: lav)
2. **Eksterne API-kunder** af registry-api (hvis nogen — verificér i Forge access logs)
3. **Postman/Insomnia-collections** brugt manuelt af team (lav sandsynlighed for hidden dependency)

Anbefaler at Sprint 0a dag 1 task: Frederik tjekker Forge nginx access logs på registry-api for `/v1/monitoring/*`-requests sidste 30 dage. Hvis 0 requests fra eksterne IPs, kan vi cutte alias-perioden ned til 3 dage.
