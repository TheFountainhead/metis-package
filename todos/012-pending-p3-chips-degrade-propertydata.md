---
status: pending
priority: p3
issue_id: "012"
tags: [code-review, chips, 2c]
dependencies: []
---
# Chips-lås-degradering dækker kun structureData — propertyData-stale er samme fejlklasse
**Problem:** layerContributesNodes() degraderer til ulåst når structureData === [] (poll-early-return-vinduet). rehydrateBeforeRebuild har TRE uafhængige guards (structure/property/enrichment); en request med populeret structure men stale-tom propertyData ville trial-bygge mod forkert propertyData → forkert låse-tilstand. Ingen demonstreret sti udløser det i dag (re-review C2-fix, 28/7) — men det er samme bug-klasse som den fixede.
**Løsning:** udvid degrade-guarden til at spejle alle tre rehydrerings-guards, eller flyt låsen til public counts som person-siden. Small.
