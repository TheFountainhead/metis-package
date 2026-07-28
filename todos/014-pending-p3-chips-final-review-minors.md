---
status: pending
priority: p3
issue_id: "014"
tags: [code-review, chips]
dependencies: []
---
# Chips final-review minors (M1-M5, 28/7)
- M1: PersonStructure kunne override enrichmentGraphNodes() m. all-layers (spejl af P1-4) — chip-fra-under-load giver én-interaktion-forsinket kort (præeksisterende 2b-klasse)
- M2: memo-persistens-pin observerer ikke sin failure mode (refleksions-pin i stedet)
- M3: "exactly once"-pin pinner cachen ikke gaten (spy/Cache::flush mellem ticks)
- M4: pending-badge viser "(0)" i 1-2s vindue — overvej '–'
- M5: refreshEnrichmentData-docblock-drift efter T2
