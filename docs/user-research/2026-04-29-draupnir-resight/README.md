# User research: Rasmus Hornhaver (Draupnir) om Resight vs. Metis

**Dato:** 29. april 2026
**Kilde:** møde mellem Rasmus Hornhaver (Draupnir Investment Advisors A/S), Frederik Nielsen og Kristian Primdal (Frankston).
**Format:** transcript (840 linjer) + 27 min skærmoptagelse hvor Rasmus delte sin Resight-skærm.
**Original-filer:** `~/Dropbox/Frankston/Kunder/Investeringsrådgivere/Draupnir Investment Advisors A:S/Noter/`
- `Draupnir 2026.04.29.txt` — fuldt transcript
- `Draupnir + Resight.mp4` — skærmoptagelse (2.4 GB)
- `Draupnir + Resight - Resumé 2026-04-29.pdf` — fuldt møderesumé inkl. ikke-Metis-relevant indhold

## Hvorfor dette bundle eksisterer

Rasmus er superbruger i Resight og Frankstons ledelse vurderer ham som det bedste tilgængelige feedback-loop til at vurdere om **Metis** kan blive en reel Resight-konkurrent. Han åbnede live sin Resight-konto og pegede på præcis hvilke features han bruger og hvilke han savner — det er de vigtigste data Metis-roadmappen kan få lige nu.

## Hvad du skal gøre med dette

1. **Læs `feature-implications.md`** — den oversætter Rasmus' feedback til konkrete user stories du kan trække ind i Metis-backloggen.
2. **Verificér mod nuværende Metis-status** — flere af Rasmus' must-haves er måske allerede dækket i Metis v2.6.0 (se `~/.claude/projects/-Users-Frederik/memory/project_metis_standalone.md`). Mark off hvad der er done.
3. **Slå op i `transcript-excerpts.md`** når du vil se det rå citat bag en bestemt feature — alle items i feature-implications.md har linje-refencer til transcriptet (T:linje).
4. **Brug screenshots** til at forstå hvordan Resight præsenterer information visuelt. Det er det Metis konkurrerer mod, både UX og data-tæthed.

## TL;DR — de to vigtigste fund

### 1. Sticky-differentiator: alerts på gælds-ændringer pr. ejendom

Resight kan **ikke** alert'e på nye ejerpantebreve, udlæg eller gælds-ændringer på fulgte ejendomme. Draupnir har "negative pledge" på mange udlån — hvis der dukker udlæg op bagefter, er det reelt default.
**Citat (T:496-518):**
> Rasmus: "Det gør det ikke på Resights."
> Frederik: "Det burde vi jo kunne, fordi vi har informationen der, så må vi jo kunne lave en delta..."
> Rasmus: "Det ville være genialt, hvis vi kunne se, at der lige pludselig kom et nyt ejerpant på her."

Dette er Metis' bedste sticky-feature. Resight har bevidst ikke prioriteret det fordi de ikke har efterspurgt det — alle med pant i fast ejendom burde have det.

### 2. Bias mod Resight er stor — gå ikke for tidligt ud

Rasmus er ærlig: **"Hvis I kommer ud med det her produkt, vil I møde en kæmpe mur af bias. Folk er bare rimelig lojale over for Resight"** (T:365). Konkurrenten **ReData** er allerede gået for tidligt ud med en kopi-løsning og har skadet sit brand. Rasmus' tålmodighed med ReData er kort.

Det betyder: Metis må ikke lanceres bredt før den har minimum 1-2 features Resight ikke har, *plus* matcher 80%+ af Resights kerne-data. **Den nuværende Metis-status (~85% af Resights data-coverage) er på rette spor — men UX og dybde i de eksisterende moduler skal være på Resight-niveau før vi promoverer.**

## Bundle-indhold

| Fil | Indhold |
|---|---|
| `README.md` | Dette dokument — entry point |
| `transcript-excerpts.md` | Resight-relevante uddrag fra mødet, attribueret med linjenumre |
| `feature-implications.md` | Konkret user-story-backlog til Metis |
| `screenshots/01-company-overview-regnskab.jpg` | Resights selskabsforside med regnskabsfaner |
| `screenshots/02-property-list-with-map.jpg` | Ejendomsliste på Danmarks-kort |
| `screenshots/03-similar-trades-and-aerial.jpg` | Lignende handler + skråfoto + matrikelkort |
| `screenshots/04-ownership-details.jpg` | Ejerforhold, ejertype, fødselsdato, statusdato |
| `screenshots/05-portfolio-overview.jpg` | Selskabs-overblik (porteføljebokse, matrikler, kort) |
| `screenshots/06-tinglysning-mortgages.jpg` | Tinglysning: ejerpantebreve, kreditorer, hovedstole |
| `screenshots/07-context-mail-thread.jpg` | Mail-tråd Frederik ↔ Ulrik om gælds-omvendt-søgning |

## Pilot-strategi besluttet på mødet

- **Lad Rasmus køre Resight + Metis parallelt** indtil Metis er god nok til at han skifter
- **20-25 % rabat** når han er klar
- **Risiko:** Han bruger Metis til "det Resight ikke kan" og bliver hængende på Resight. Modforanstaltning: konkret feedback-forpligtelse + start med sticky-feature (alerts på gæld) som først motiverer skiftet
- **Frankston honoreres** for værdipapir-rapport-modulet til Ringkøbing/Nordea-kunder via højere pris/kunde — dette er ikke Metis-scope, men afgør cashflow til at finansiere Metis-udvikling

## Næste skridt (ift. Metis)

1. Cross-check `feature-implications.md` mod current v2.6.0 for at se hvad der allerede er bygget
2. Prioritér **gælds-alert på fulgte ejendomme** som næste Metis-milepæl (sticky-feature)
3. Tilbyd Rasmus tidlig adgang til alert-funktionen så han co-tester
