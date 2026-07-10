# Resights pricing — extracted via agent-browser 2026-05-02

Source: https://resights.dk/priser/ (Cloudflare WAF — solved JS challenge via agent-browser)

## Headline tiers (DKK/user/year ex VAT)

| Tier | 1 user | 2 users | 3 users | 5 | 10 | 15 | 20 | 25 | 26+ |
|------|------:|--------:|--------:|---:|---:|---:|---:|---:|----:|
| Pro | 15.990 | 14.250 | 11.667 | 11.000 | 9.000 | 8.000 | 7.500 | 7.000 | "Kontakt os" |
| Pro Plus | 21.990 | 20.000 | 15.833 | 14.000 | 11.500 | 10.333 | 9.250 | 8.400 | "Kontakt os" |
| Insights | 34.990 | 27.500 | 21.667 | 19.000 | 14.000 | 12.000 | 11.000 | 10.400 | "Kontakt os" |

## Tier features

### Pro (15.990 DKK)
- Kort (kortlag, fredninger, bebyggelsesprocent, ejerforhold)
- Udforsk (filtrer ejendomme/virksomheder)
- Undersøg (ejerforhold, vurderinger, servitutter, hæftelser)
- Portefølje (ejerskabstræer, komplette ejendomsporteføljer, finansiel udvikling på tværs af selskaber)
- Handels- og udbudsagent
- Følg (overvågning af person/virksomhed/ejendom)
- Account Manager + support
- Brevflet (automatiserede breve)
- Lejedata * (kræver indberetning af egne lejedata)

### Pro Plus (21.990 DKK)
- Alt fra Pro
- Avanceret udtræk (Excel ned på enhedsniveau)
- Handler, afkast og statistik (transaktionsdatabase + skjulte selskabshandler beriget m. afkast/rådgivere/lejebærende areal)
- **Sampantsskema** (finansierings-indsigt: pålydende sats, hovedstol, rentetype, kreditor, ejendomme lånet berør)
- Lejedata * (samme krav som Pro)

### Insights (34.990 DKK)
- Alt fra Pro Plus
- Analyser (ejendomme + erhverv + befolkning visualiseret — positiv tilvækst, potentiale for rækkehuse osv.)

## Øvrige ydelser (separate listed)

- API
- Datarum
- Dataudtræk

(Ingen offentlige priser i hovedtabellen — separat dialog.)

## Observations relevant for Lender Intelligence

1. Resights HAR offentlige priser (memory feedback_dont_confabulate confirmed — 451 WAF was solvable, not a "no-public-pricing" signal).
2. Volume discount aggressivt: 1 user = 15.990, 25 users = 7.000/user (56% rabat).
3. **Sampantsskema (Pro Plus) er det DIREKTE lender-overlap**: pålydende sats, kreditor, ejendomme lånet berør. Men det er stadig ejendoms-data, ikke loan-portfolio-management.
4. Tier-filosofi: per-bruger/år, ikke per-loan eller per-AUM. Frankston's tentative per-loan-pricing er en differentieret model.
5. Insights-tier (34.990) topper ud — ingen "Lender" eller "Risk" tier. Hul i markedet bekræftet.
6. Modul-features (Lejedata, Sampantsskema) er gating'et som add-ons / requires-data-contribution.
