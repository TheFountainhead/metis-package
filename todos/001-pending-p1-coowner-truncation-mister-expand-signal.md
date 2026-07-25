---
status: pending
priority: p1
issue_id: 001
tags: [code-review, data-integrity, graph]
dependencies: []
---
# Co-ejet ejendom trunkeret → kun én ejer får expand-signal

## Problem Statement
`OwnershipGraphBuilder::removeNode`: parentId findes via array_filter-closure der OVERSKRIVER variablen pr. kant hvor to===removedId. Ved en ejendom med flere ejer-kanter (dedup-shapen der eksplicit understøttes) får kun den SIDSTE ejer +1 på expand.properties — de øvrige ejere mister både ejendommen og signalet. Kreditværktøj ⇒ stille undervurdering af portefølje (bryder null≠tom).

## Findings
- data-integrity-guardian (CONFIRMED m. isoleret PHP-repro af closure-logikken)
- Relateret asymmetri (architecture-strategist P2): fjernet nodes egen expand.properties rulles ikke op (benign i dag pga. pass-orden — dæk m. test i samme fix)

## Proposed Solutions
1. (Anbefalet) removeNode: saml ALLE from-ejere af den fjernede node og increment expand[field] på hver. Effort: Small. Risk: Lav (ren builder + tests).
2. Undtag co-ejede ejendomme fra trunkering. Effort: Small. Risk: cap kan overskrides.

## Acceptance Criteria
- [ ] Test: ejendom m. 2 ejere trunkeres → BEGGE ejere får expand.properties +1 (og capped_properties)
- [ ] Eksisterende 257 tests grønne
