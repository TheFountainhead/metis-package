---
status: pending
priority: p3
issue_id: "010"
tags: [code-review, quality, 2b]
dependencies: []
---
# RegistryApi: pool-boilerplate-duplikering + cpr-cache-key-test kunne enumerere alle keys
**Problem:** fetchCompanyStructuresPooled spejler fetchCompanyInfosPooled næsten ordret (bevidst mirror-instruks, T2); drift-risiko hvis client() får headers. Cpr-key-testen asserterer én gættet u-hashet key frem for at enumerere alle skrevne keys.
**Løsning:** Fælles pool-helper + array-driver key-enumeration i testen. Small.

## Navnekonventions-gap: `...Cached` vs `...FromCache`

To modsatrettede betydninger deler næsten samme navn, og forskellen er
sikkerhedsrelevant fordi de kaldes fra hver sin request-type:

- `fetchCompanyStructureCached()` / `fetchCompaniesByCprCached()` /
  `fetchCrossOwnershipCached()` — læser cache, men **falder igennem til et
  rigtigt kald** ved miss.
- `fetchCompanyStructureFromCache()` / `fetchCompanyInfosCached()` /
  `fetchCompanyPropertyPortfolioCached()` — **cache-only**, aldrig HTTP.

Bemærk at `fetchCompanyInfosCached()` og
`fetchCompanyPropertyPortfolioCached()` bærer `...Cached`-suffikset, men er
cache-only — så suffikset forudsiger i dag ikke adfærden.

Det har allerede kostet én gang: recovery-stien kaldte
`fetchCompanyStructureCached()` i den tro at den var cache-only, hvilket
gjorde ét chip-klik til op til 20 sekventielle POSTs (se
`recoverStructureResults()`'s docblock).

**Løsning:** vælg ét skema og gennemfør det — forslag `...Cached` =
read-through, `...FromCache` = cache-only — og omdøb de to afvigere.
Small, men rør alle kaldesteder.
