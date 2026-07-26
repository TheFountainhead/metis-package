---
status: pending
priority: p3
tags: [code-review, patterns]
---
# Cache-nøgler afviger fra filens konvention
`metis.company-info.{cvr}` / `metis.company-structure.{cvr}` (punktum-kebab) vs. filens `metis:snake_case:{param}`. Omdøb til metis:company_info:{cvr}-stil (ét cache-miss v. deploy, harmløst). Fund: pattern-recognition.
