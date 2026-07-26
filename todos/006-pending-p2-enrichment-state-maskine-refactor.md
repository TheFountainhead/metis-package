---
status: pending
priority: p2
issue_id: 006
tags: [code-review, refactor, 2a2]
---
# Enrichment-tilstandsmaskinen bør samles (post-2a.2)
To parallelle status-enums (propertiesStatus 5-værdi, enrichmentStatus 3-værdi) + 3 gates i loadEnrichment + rehydrerings-triade. F1-F6+retry-fix-historikken (5 commits på samme fil) viser at maskinen voksede organisk. Forslag (taylor+simplicity-agenterne): afled enrichment-overgange inline hvor propertiesStatus settler (én maskine), evt. EnrichmentGate-ekstraktion. Tag SAMMEN med todo-004 (builder-refactor) når 2b rører laget alligevel. Ingen adfærdsændring — 301 tests er sikkerhedsnettet.
