---
status: pending
priority: p3
issue_id: "013"
tags: [code-review, error-handling]
dependencies: []
---
# PersonProperties: null≠tom-gap — fejl vises som tom sektion
**Problem:** mount() rescue'r fetch-fejl til tom liste; sektionen har ALDRIG haft hasError-logik (opdaget C3-review 28/7 — 27/7-fixet rørte kun PersonCompanies/CompanyTinglysning). En timeout ligner "ingen ejendomme".
**Løsning:** CompanyTinglysning-mønstret (hasError/errorMessage + error-state-partial). Small.
