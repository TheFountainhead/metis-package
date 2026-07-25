---
status: pending
priority: p3
tags: [code-review, refactor, 2a2]
---
# Builder-refactor-kandidater (tag i 2a.2)
Samlet fra taylor/pattern/simplicity/arch — ingen adfærdsfejl, tages når 2a.2 alligevel rører builderen:
- by-ref-parameter-threading → GraphAccumulator/instance-state (docblock lover "pure" mere end implementeringen)
- removeNode's string-$field → to navngivne metoder eller enum
- expand-mutation-idiomet (foreach &$node … unset) ×3 → privat updateNodeExpand-helper
- caps hardcodet i rebuild() → klassekonstanter
- fetchCompanyStructureCached vs fetchCompanyStructure: overvej samlet metode m. TTL-styring i RegistryApi
- rehydrateBeforeRebuild-guards → ét eksplicit hydrations-flag når enrichment-state (2a.2) tilføjes
