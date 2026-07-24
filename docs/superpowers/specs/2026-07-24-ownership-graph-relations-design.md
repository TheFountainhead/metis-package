# Metis: Ejerstruktur & relationer — fri-form graf (Resights-lignende)

**Dato:** 2026-07-24
**Repo:** metis-package (render) — registry-api genbruges uændret
**Status:** Design godkendt, klar til fase 1-plan

## Problem / vision

Det nuværende ejer-org-chart (CSS-grid, deployet 24/7) viser koncern-kæden pænt, men:
- Ved store koncerner (BDO Finans: 85 ejere) sprænger grid'et — kræver zoom (bygget).
- Det viser KUN ejerskab. Resights' diagram er langt rigere: person → selskaber → **ejendomme**, med CVR/branche/BFE/anvendelse pr. node, ejerandels-**intervaller** på kanterne, og et **fri-form graf-layout** (skrå kanter, spredte noder) frem for stringent grid.

Målet: et Resights-lignende **relations-diagram** — fri-form graf, person øverst, ejerskab + ejendomme + aktieposter som lag man tænder efter behov, i frankston.io-stil.

## Beslutninger (fra brainstorm)

- **Layout:** fri-form graf (variant C), IKKE CSS-grid. Kræver et graf-layout-bibliotek.
- **Stil:** frankston.io editorial (sand #f6efe3, Spectral serif, IBM Plex Mono, teal/oxblood) — IKKE Resights' hvide tema. Konsistent med resten af Metis. Undtagelse: person-node er mørk (#2b2333), som Resights.
- **Retning:** person/ultimative ejere ØVERST → ejerskab flyder NED → selskaber → ejendomme nederst.
- **Lag-model:** toggles, IKKE alt-blandet. Ejerskab altid på; Ejendomme / Aktieposter / Gæld tændes efter behov. Respekterer reglen: aktieposter (~25.888 shareholder-kanter) må ALDRIG blandes i koncern-/ejendoms-tal — de er et distinkt kant-lag.
- **Ejendoms-omfang:** hele koncernens ejendomme (alle selskaber i grafen), lazy-hentet når laget tændes.
- **Rig node-info:** selskaber viser CVR + branche; ejendomme viser BFE + anvendelse; kanter viser ejerandels-interval (CVR's rå interval, fx "33,5-50%", ikke afrundet tal).
- **Zoom/pan:** genbrug det byggede (auto-fit + +/− + træk + scroll-hjul). Nødvendigt for store grafer.

## Arkitektur

### Graf-layout-bibliotek
- **dagre** (npm, bundlet via metis' Vite-build — IKKE CDN, CSP blokerer). Beregner hierarkisk layout: node-x/y + kant-koordinater fra en {nodes, edges}-model.
- Alternativ overvejet: elkjs (kraftigere men tungere), d3-hierarchy (kun træer, ikke generelle grafer med diamanter). dagre er den rette balance.
- **Fase 1 skal VERIFICERE integrationen først** (spike): at dagre kan bundles i metis' Vite-build, køres fra Alpine/Livewire efter data-load, og re-layoute ved lag-toggle/poll — FØR resten af fase 1 bygges. Hvis dagre viser sig at klemme dårligt med Livewire's DOM-morphing, er fallback @dagrejs/dagre + manuel SVG-render, eller elkjs. Antag ikke; bevis det i et lille spike-trin.

### Render
- Data hentes af Livewire-komponenten (som nu: `getOwnershipChain` + koncern nedad + lazy ejendomme).
- Layout beregnes i **JS** (dagre) efter data er i DOM/Alpine-state.
- Noder = absolut-positionerede **HTML-kort** (bevarer frankston-stil + rig info + `{{ }}`-escaping mod XSS).
- Kanter = **SVG-linjer** med interval-labels.
- Wrapper = zoom/pan-container (genbrug fra org-chart-arbejdet).

### Datalag (lazy)
| Lag | Kilde (registry-api) | Toggle | Fase |
|---|---|---|---|
| Ejerskab (basis) | `getOwnershipChain` (opad) + koncern nedad (legal_owner) | altid på | 1 |
| Ejendomme | `KoncernPortfolioService` / `company/{cvr}/property-portfolio` | ja | 2 |
| Aktieposter | `cvr/company-relations` (shareholder-kanter, distinkt kant-stil) | ja | 3 |
| Gæld | `companies/{cvr}/tinglysning-overview` | ja | 3 |

registry-api genbruges 100% — alle fire lag har allerede endpoints/services. Arbejdet er koncentreret i metis-render.

## Node- & kant-design (frankston-stil)

- **Person-node:** mørk (#2b2333), hvid tekst, 👤. (Som Resights.)
- **Selskab-node:** sand-kort, Spectral-navn, mono-rækker "CVR-NUMMER / BRANCHE". 🏢. Søgt selskab: bg-2 + tyk ink-kant.
- **Ejendom-node:** stiplet kort, ochre titel (#8a6d1f), 🏠, mono-rækker "BFE / ANVENDELSE".
- **Kant:** tynd rule-linje (#b8a884); ejerandels-interval i lille sand-label midt på. Aktiepost-kanter: stiplet/prikket + oxblood, tydeligt adskilt.
- **"Udvid N relationer"-affordance** pr. node (lazy expand op/ned), som Resights.

## Faseplan (hver fase = selvstændig leverance)

**Fase 1 — fri-form ejerskabs-graf (afløser org-chart-render).**
- dagre bundlet + graf-render af ejerskabs-laget (person/ejere øverst → søgt selskab → datterselskaber nedad).
- Rig node-info (CVR, branche) + intervaller på kanter.
- Zoom/pan (genbrug). Erstatter den nuværende CSS-org-chart-blok.
- registry-api uændret.

**Fase 2 — ejendoms-lag.**
- Toggle "Ejendomme". Lazy-hent koncernens ejendomme (`KoncernPortfolioService`) når tændt.
- Ejendoms-noder hængt på deres ejende selskab i grafen, med BFE/anvendelse.

**Fase 3 — aktieposter + gæld.**
- Toggle "Aktieposter" (distinkt kant-lag, aldrig i koncern-tal).
- Toggle "Gæld" (tinglysning-overview pr. selskab/ejendom).

## Edge cases / risici

- **Store grafer (85+ noder):** dagre beregner layout, men kan blive tungt. Bound: begræns initial dybde/bredde, lazy-expand resten. Zoom/pan gør det navigerbart.
- **Cykler/diamanter:** dagre håndterer generelle grafer; genbrug cycle-guard-data fra `getOwnershipChain`.
- **CSP:** dagre bundles i Vite (npm), ingen CDN. Same-origin fonts (allerede fikset).
- **Performance ejendomme:** lazy pr. lag-toggle, ikke ved initial load. Koncern-portfolio-service er bygget til dette.
- **Afløser deployet org-chart:** fase 1 erstatter render-laget. Rollback = revert til org-chart-commit. registry-api urørt, så ingen data-risiko.

## Non-goals (denne spec)
- Ændringer i registry-api's data (ejerskab/ejendom/gæld-services genbruges).
- Redigering/eksport af grafen (kun visning).
- Resights' hvide tema (vi holder frankston-stil).
