---
status: pending
priority: p3
tags: [code-review, agent-native, 2b]
---
# Berigelses-sammensætningen er UI-eksklusiv (agent-native gap)

agg/signals/card-sammensætningen (inkl. newly_founded-12-mdr-vinduet og
dækningsgrad) findes kun i Livewire-state — ingen maskinlæsbar vej.
Acceptabelt i 2a.2; genbesøg v. 2b: overvej at eksponere builderens output
(eller signal-reglerne som dokumenteret kontrakt) via endpoint/artisan.

## Scope efter 2b (opdateret v. agent-native review)

Scopet dækker nu **både** `build()` (2a: selskabs-grafen) og
`buildForPerson()` (2b: rolle-lag, cross-ownership-demotion, first-level caps
`person_roots`/`person_roles`). De to indgange har forskellig
trunkeringsrækkefølge og forskellige cap-felter, så et endpoint der kun
eksponerer `build()` ville give et misvisende svar på person-siden.

**Forudsætning når dette aktioneres:** `classify()` skal ekstraheres fra
`PersonStructure` først. Reglerne (kun `is_active`, `has_direct_ownership`
som ejerskab/rolle-split, ejerandel = første current rolle der bærer en,
`role_label = title ?? role`) er i dag komponent-private, men de bestemmer
builderens input — et endpoint uden dem ville skulle duplikere dem og
dermed kunne drifte fra UI'et.

**Response-kontrakten er CPR-sikker by construction:** person-roden er
`person:root` og bærer intet cvr, og `personLabel()` udleder aldrig navnet af
CPR-cifrene. Builderens output kan derfor serialiseres direkte uden et
separat scrubbing-lag — men et fremtidigt endpoint må ikke selv ekko
request-parameteren (CPR) tilbage i svaret.
