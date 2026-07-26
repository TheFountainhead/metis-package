---
status: pending
priority: p3
tags: [code-review, wontfix-log]
---
# Accepterede designvalg (dokumentation af review-adjudikering)
- fetchPropertiesBatch all-or-nothing (taylor P3): bevidst — delvist resultat ville vise ejendomme uden anvendelse uden forklaring
- onWheel/zoomBy sætter _userInteracted uden tærskel (julik P3): bevidst — zoom-klik/wheel ER bevidste handlinger
- Auto-refit spænder over async-merges indtil første interaktion (arch P3): bevidst spec-beslutning
- capped_*-flag vs skip-truncation-for-expanded (simplicity P2-alternativ): nuværende løsning testet+deployklar; revurdér i 2a.2
- To busy-spinnere kan køre samtidig på én node (julik): Livewire serialiserer actions; ret kun hvis observeret
- Cache::get/put-par vs remember (pattern P2): remember kan ikke udtrykke cache-ikke-tomt
- Agent-native P3: udvidet/afskåret visning findes kun i UI-state — de rå endpoints giver fuld data; acceptabelt for 2a.1
- Git-history P2: cap/expand-feltet ramt 3× i fix-kæden → skrøbelighedsflag: kør builder-testfilen FØR enhver fremtidig ændring dér

## Tilføjet efter multi-agent-review af #112 (26/7)
- substr($id,4) på 'bfe:'-prefix: FALSK POSITIV fra pattern-agent ('bfe:' er 4 tegn) — verificeret m. php -r + eksisterende tests
- equity===0 → intet signal (hverken negative_equity eller no_financials): bevidst grænse (<0); 0-egenkapital vises neutralt
- enrichmentStatus har ingen 'building'-mellemtilstand: bevidst — berigelsen ER hurtig (pooled+cachet); building-UX hører til properties
- foreign/other-noder kan klik-navigeres men beriges ikke: navigation ≠ berigelse (krydsreference-kommentarer tilføjet)
- fetchCompanyInfosPooled genopbygger auth manuelt (kan ikke genbruge PendingRequest til pool): dokumenteret i kode
- Julik P3 (closest() på detached element i touch-vindue): meget lav sandsynlighed; cache closest-resultatet hvis vandtæthed ønskes senere
