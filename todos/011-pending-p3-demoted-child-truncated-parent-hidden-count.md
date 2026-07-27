---
status: pending
priority: p3
issue_id: "011"
tags: [code-review, correctness-edge, 2b]
dependencies: []
---
# buildForPerson: demoted child hvis parent røg ud af roots-cappen droppes stille
**Problem:** T5-⚠️/final review-triage: et cross-ownership-barn hvis forælder ligger UDEN for de 20 synlige rødder renderes ikke og tælles ikke i person-rodens hidden-count (off-by-one i sjælden >20-rødder+cross-ownership-form). Ingen orphan/dangling edge — kun undertælling.
**Løsning:** Medregn barnet i $hidden når forælderen trunkeres. Small; kræver fixture m. 21+ rødder.
