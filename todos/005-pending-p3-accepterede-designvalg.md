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
