---
status: pending
priority: p3
issue_id: "008"
tags: [code-review, performance, 2b]
dependencies: []
---
# Person-fase-3: building-backoff som 2a (flat 2s-ticks i dag)
**Problem:** Spec sagde backoff "som 2a"; implementeringen ticker fladt hvert 2s, så 3 samtidigt-'building' porteføljer brænder det delte 24-budget på ~16s wall-clock. retryProperties() resetter budgettet, så det degraderer til en retry-affordance — accepteret i final review som M6, noteres i PR-beskrivelsen.
**Løsning:** Eskalerende per-cvr backoff (2a's mønster) eller budget-uafhængig deadline. Small.
