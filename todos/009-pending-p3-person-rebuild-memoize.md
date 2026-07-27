---
status: pending
priority: p3
issue_id: "009"
tags: [code-review, performance, 2b]
dependencies: []
---
# PersonStructure: rebuild() kører builderen 3-4x pr. action
**Problem:** rebuild() bygger grafen + 2x visibleFirstLevelCvrs; toggleLayer 4x. Ren CPU ved ≤120 noder (mikrosekunder) — final review M7.
**Løsning:** Per-request memoization af builder-kaldet. Small.
