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
