# Go-to-market: Metis som "Kreditorernes Resight" (1. maj 2026)

**Formål:** Hvordan kommer Metis i kontakt med Resights kunder? Hvilke segmenter rammer vi først, og hvordan? Hvad er pricing-spillet? Hvordan undgår vi ReData-fælden (T:802)?

**Strategisk grundlag:**
- Resight har 1.300 kunder / 4.000 brugere fordelt på 8 segmenter
- INGEN er bygget til **kreditorer** (banker/realkredit/credit-fonde/advokater)
- Frankston har 5+ data-edges Resight ikke har (mortgage-delta, AVM, credit, AIS, eSkat)
- Bias-mur er stærk for investor-segment, **mindre stærk for kreditor-segment** (de er ikke daily users af Resight, kun lejlighedsvist)

---

## 1. Markedssegmentering

### 1.1 Primært målsegment (12 måneder): Kreditor-segment DK

| Segment | Antal organisationer | Brugere/org | Total brugere | Samlet ARR-potentiale @30K/bruger |
|---|---|---|---|---|
| **Realkredit-institutter** | 6-7 (Nykredit, RD, Totalkredit, BRF, DLR, Jyske, Sparbank) | 50-200 | 300-1.400 | DKK 9-42M |
| **Banker med ejendomslån** | 70+ (Danske, Nordea, Jyske, alle sparekasser) | 5-20 | 350-1.400 | DKK 10-42M |
| **Ejendoms-credit-fonde** | 20-40 (Draupnir, Nordic Bloom, Dahlerup Capital, Faktorkredit, m.fl.) | 2-10 | 40-400 | DKK 1-12M |
| **Family offices med ejendoms-PE** | 50-80 (Augustinus, JP/Politiken, m.fl.) | 1-5 | 50-400 | DKK 1.5-12M |
| **Advokat-kontorer (insolvens, fast ejendom)** | 100+ (Bech-Bruun, Plesner, Kammeradvokaten, m.fl.) | 1-5 | 100-500 | DKK 3-15M |
| **Leasing/factoring (ejendoms-relateret)** | 30+ | 1-5 | 30-150 | DKK 1-4.5M |
| **Forsikring (ejendomsdækning)** | 5-10 (Topdanmark, Tryg, Codan) | 5-20 | 25-200 | DKK 0.7-6M |
| **TOTAL** | **300-400 orgs** | — | **900-4.500 brugere** | **DKK 26-134M ARR-pool** |

**Konservativt 3-års-mål:** 30% capture × 1.000 brugere × DKK 30K = **DKK 9M ARR**.
**Optimistisk 3-års-mål:** 50% capture × 2.000 brugere × DKK 35K = **DKK 35M ARR**.

### 1.2 Sekundært segment (12-24 måneder): Investor + advisory

Når kreditor-cases er beviset:
- Ejendomsinvestorer (Resight's kerne, men nu med Metis sticky-features de ikke kan miste)
- Advisor til ejendomsinvestorer (familieselskaber, formueforvaltning)
- Bestyrelser i ejendomsselskaber (Rasmus' egen idé T:359 "billig version af Resights")

### 1.3 Tertiært (24-36 måneder): International ekspansion

- Norge: tilsvarende tinglysningsregister, Brønnøysund (CVR-pendant)
- Sverige: Lantmäteriet (matrikel) + Bolagsverket (CVR-pendant). Standout-investerede Resight er allerede her — vi følger efter
- Tyskland: store markedsstørrelse, mere fragmenteret data-landskab

---

## 2. Resights kunde-base — hvor finder vi dem?

### 2.1 Verificerede signaler om hvem Resight har

Fra videoframes og tredjepartskilder:
- Frame 010 viser Rasmus' egen Resights-konto (= 1 af 4.000 brugere — kreditor-segmentet)
- "Lister" på frame 020: "Tidl. følg kategori: Ikke kategoriseret (Fir...)" og "(Per...)" — multi-bruger pattern
- byensejendom.dk: "1.300 kunder, 3.700 brugere, 45 ansatte"
- bygge-anlaegsavisen.dk: "ejendomsinvestorer, bygherrer, entreprenører, arkitekter, leverandører"

### 2.2 Hvor finder vi dem konkret?

#### Direct-outreach kanaler
1. **LinkedIn Sales Navigator-søgninger:**
   - "Credit Officer" + "Property" + Denmark
   - "Risk Manager" + "Real Estate" + Denmark
   - "Pant" eller "Pantsætningsforbud" i profil
   - "Workout" + "Property" / "Restructuring"
   - Stillingsbetegnelser: "Investeringschef", "Risikodirektør", "Kreditdirektør"
2. **Resights' offentlige kunde-side** (resights.dk/kunder/) — vi skal se hvilke logoer der vises og kontakte dem direkte. Skal scrapes via firecrawl med JS-rendering.
3. **Finans Danmarks medlemsliste** + arbejdsgrupper for ejendomsbelåning
4. **Realkreditforeningens** medlemsliste
5. **Advokatforeningens** insolvens-sektion + fast ejendoms-sektion
6. **Børsen / FinansWatch / EjendomsWatch** — annoncér "Metis lancerer kreditor-løsning" → relevante journalister contacter os
7. **Mastercard's ejendoms-segment** (vores partner via Leverandørservice) — referrals

#### Indirect channels
8. **Draupnir's eget netværk:** Rasmus + Ulrik Larsen (AIF design partner) introducerer 5-10 peers i credit-fond-rummet
9. **Konference-tilstedeværelse:** Boligkonferencen, Realkreditdagen, Insolvensretskonferencen
10. **Frankston-master kunder:** Dannebrog, F10, Draupnir m.fl. har formentlig kreditor-relationer der kan introducere
11. **Knowledge Base content:** `/knowledge` bygger expert-content om "tinglysning-monitor", "negative pledge", "udlæg-detektion" — SEO + thought leadership

### 2.3 "Kvalificerede leads" via egen tinglysning-data

**Geniale (og etiske) leads-strategi:**

Vores Tinglysning-crawl ser **HVEM** der modtager nye pantebreve på fast ejendom hver dag. Det er en liste af aktive kreditorer i Danmark. Vi kan:

1. Aggregere "top 100 kreditorer på pantebreve sidste 30 dage"
2. Slå dem op i CVR for kontakt-info
3. Direct outreach: "Jeres pant på X ejendomme i Q1 2026 ville få realtids-overvågning af salgsforbud-overtrædelser hvis I brugte Metis"

Det er en perfectly legal, fully data-driven prospect-pipeline ingen konkurrent kan kopiere.

---

## 3. Positionering & Messaging

### 3.1 Kerne-narrativ

**"Metis er bygget til dem der har lånt penge til fast ejendom — ikke kun til dem der køber den."**

Tre kerne-budskaber per pitch:

1. **Realtids-overvågning der eksisterer ingen andre steder.** Du får besked dagen efter et udlæg eller nyt pantebrev tilskrives en ejendom du følger. *Ingen* konkurrenter har det.

2. **Find dyre lån du kan refinansiere.** Søg gæld i stedet for ejendomme. "Find ejendomme i 8000-9999 postnumre med ejerpantebrev rente >10% ejet af selskab" → CSV-eksport.

3. **Hele kreditor-toolchain'en samlet.** AVM + credit-scoring + stress-test + pantebrev-monitor + (snart) bankkontodata + skat-data — alt under ét login. Resight, ReData, Bisnode er fragmenterede.

### 3.2 Anti-narrativ (hvad vi IKKE siger)

- ❌ "Vi har bredere data end Resight" (vi har ikke — leje-data + byggeri)
- ❌ "Vi er billigere end Resight" (priskrig kommer ikke til at drive switching cost)
- ❌ "Vi har bedre AI" (Rasmus stoler ikke på Resights, og vi har ikke noget at vise endnu)
- ❌ "Vi er en Resight-konkurrent" (det er ReData-fælden — bias-mur, ikke vores kamp)

### 3.3 Tagline-kandidater

- "Den første ejendomsdataplatform der ringer til dig når dit pant er truet."
- "Resight viser hvad der ér. Metis viser hvad der ændrer sig."
- "For dem der låner penge til fast ejendom."
- "Tinglysningsretten varsler ikke. Det gør Metis."

(Foreslå A/B-test af tagline efter pilot-cases er publiceret.)

### 3.4 Demo-flow til kreditor-pilot

Standard 30-minutters demo, baseret på Rasmus' wow-momenter:

```
[0-3 min] "Jeg ved du bruger Resight. Jeg vil ikke prøve at få dig til at skifte. 
           Men jeg vil vise dig 3 ting Resight ikke kan gøre."

[3-10 min] DEMO 1: Omvendt gælds-søgning
           "Find alle ejerpantebreve over 10% rente i postnumre 8000-9999, 
           ejet af selskab. Eksportér som CSV." → 23 matches → Excel-fil downloaded.
           Wow-moment: "Det er mine refinansierings-prospects for næste kvartal."

[10-18 min] DEMO 2: Mortgage-delta-alerts på fulgt ejendom
            "Følg en af jeres pant-ejendomme. Næste dag der sker tinglysning, 
            får du email med 'før vs nu'-diff." → email-template + skærm-mockup.
            Wow-moment: "Det er vores negative pledge-violation-detektor — 
            det vi bruger 3 timer/uge på at manuelt tjekke."

[18-25 min] DEMO 3: Portfolio-Tinglysning-tab på holding-selskab
            "Et selskab du har pant i. Klik Tinglysning-tab. 18 pantebreve på tværs af 
            6 ejendomme, total hovedstol 247M DKK. Klik 'Følg ændringer på alle 18'."
            Wow-moment: "Det er hele kunde-eksponeringen i ét view + automatisk monitor."

[25-30 min] Pilot-aftale: gratis 6 mdr mod feedback + case-rettighed.
            Spørg om 1-2 introduktioner til peers.
```

---

## 4. Pricing strategy

### 4.1 Tier-struktur

| Tier | Pris/bruger/år | Inkluderet |
|---|---|---|
| **Standalone Free** | 0 DKK | Søg + lookup, 10 søgninger/dag, ingen følg/alerts. Lead-magnet. |
| **Pro** (investor/mægler) | DKK 18.000 | Alle moduler ekskl. kreditor-pakke. Følg op til 50 entiteter. Email-digest. |
| **Creditor** (banker/fonde/advokater) | DKK 30.000 | Pro + Tinglysning-delta-alerts + Omvendt søgning + AVM API + Credit scoring + CSV-eksport. Følg ubegrænset. |
| **Enterprise** (>10 brugere) | DKK 25.000 (volumen-rabat) | Creditor + dedicated CSM + SLA + Open API access + custom alerts |

**Sammenligning:**
- Resight basis ~15K DKK/bruger (uden lejedata) — vi er +3K på Pro
- Resight m. lejedata estimat ~25-30K — vi er på par med Creditor
- ReData Standard ~91K DKK/år (1 bruger) — vi er **3-5x billigere**
- ReData Premium ~273K DKK/år — vi er **9x billigere**

**Hvorfor ikke priskrig vs Resight?** Fordi data + UX paritet ikke gør forskellen for kreditor-segmentet. Vi tager **+3K DKK premium på Pro** og **+15K på Creditor** for differentiator-features. Det er ~25% premium for "alerts + omvendt søgning" — fair value givet Rasmus' "3 timer/uge" manual workload.

### 4.2 Pilot-rabat-strategi

- **Pilot 1 (Rasmus / Draupnir):** 25% rabat (aftalt T:766) — DKK 22.500/bruger
- **Pilot 2-5 (kreditorer):** Gratis 6 mdr mod feedback + case-rettighed → DKK 30K/bruger fra måned 7
- **Pilot 6+:** Standard pricing

### 4.3 Add-on'er (commercial up-sell)

- **AIS Banking Data add-on:** DKK 15K/bruger/år — bankkonto-aggregation til kreditrisikovurdering (kommer når sandbox lander)
- **eSkat add-on:** DKK 10K/bruger/år — skat-historik (kreditformidler-vej fra 1. juli)
- **Custom alert-rules engine:** DKK 5K/bruger/år — specifikke filter-kombinationer som auto-alerts
- **API access:** DKK 25K/år flat fee — integration mod kreditors interne systemer
- **White-label:** DKK 100K-300K/år — for enterprise-kunder der vil have Metis i egen branding

---

## 5. Kanal-strategi & content marketing

### 5.1 Phase 1 (uge 0-12): Direct outreach til 50 kreditor-prospects

**Aktion:**
- Frederik bygger 50-person prospect-liste fra LinkedIn + tinglysning-data
- 5 pr. uge personalized cold-emails via fred@frankston.io (HTML-styling per `feedback_wide_email_drafts.md`)
- Follow-up cadence: dag 0, 5, 12, 21
- Mål: 10 demos booked, 3 pilots signed

### 5.2 Phase 2 (uge 12-24): Content + thought leadership

**Aktion:**
- 2 blog-posts/måned på `metis.frankston.io/blog`:
  - "Hvordan vi opdager negative pledge-overtrædelser dagen efter de sker"
  - "Refinansierings-prospects: hvad gemmer sig i Tinglysningsretten"
  - "AVM vs offentlig vurdering: hvilken skal du bruge til LTV-beregning"
  - "Pantebrev-prioritet 101 for kredit-officerer"
  - Case-historie: "Sådan brugte Draupnir Metis til at fange et udlæg dag 1"
- LinkedIn-articles fra Frederik (1/uge) — kreditor-vinkel
- Gæsteindlæg på FinansWatch + EjendomsWatch + Børsen om "kreditorers data-forudsætninger i 2026"
- Webinar Q3 2026: "Den moderne kreditors data-stack" — invitér peers fra realkredit + advokater

### 5.3 Phase 3 (uge 24-52): Partner-channel + events

**Aktion:**
- Mastercard partner-channel → realkredit-introduktion
- Konference-stand på Boligkonferencen + Realkreditdagen
- Co-marketing med Frankston-master kunder
- Open API → DevDay event for system-integrators

---

## 6. Pilot-aftaler — konkret framework

### 6.1 Pilot 1: Draupnir (Rasmus, allerede aftalt)

**Status:** Aftalt parallel-kørsel Resight + Metis indtil Metis er god nok (T:766). 25% rabat ved skifte.

**Risiko (T:766):** Han bruger Metis til "det Resight ikke kan" og bliver hængende på Resight.

**Mitigering:**
- Strukturer pilot som **9-måneders aftale** med kvartalsvise feedback-checkpoints
- 3 måneders gratis (mens vi bygger sprint 0-2)
- 6 måneders 25%-rabat når F1+F2 er LIVE
- Krav til feedback: 1 hour/månded session med Frederik + Kristian
- "Skifte-tærskel"-aftale: når Rasmus erklærer "Metis er nu mit primære værktøj", går han til 100% Metis i 12 mdr

### 6.2 Pilot 2-3: Realkredit-institut

**Profil:** 1 af de 6-7 (formentlig BRF, Jyske, eller Sparbank — mindre, mere fleksible end Nykredit/RD)
**Pitch:** "Workout-team får realtids-monitor af pant-ejendomme. Test gratis i 6 mdr."
**Outreach via:** Frederik LinkedIn → Risikodirektør / Kreditdirektør

### 6.3 Pilot 4-5: Advokat-kontor (insolvens)

**Profil:** Bech-Bruun, Plesner, eller Kammeradvokaten — insolvensafdelingen
**Pitch:** "Følg konkurser i realtid. Modtag alerts på alle ejendomme i konkursbo dagen tinglysningen sker."
**Outreach via:** Frederik LinkedIn + Advokatforeningens insolvens-sektion

### 6.4 Pilot 6: Family office med ejendoms-PE

**Profil:** Augustinus Fonden, JP/Politiken, eller mindre family office (nemmere at få ja)
**Pitch:** "Monitorér jeres ejendomsporteføljes pant-eksponering på tværs af alle holding-selskaber."
**Outreach via:** Existing Frankston-master relationer

---

## 7. Forventet respons-mønster fra kreditor-segmentet

### 7.1 Sandsynlige indvendinger

| Indvending | Modsvar |
|---|---|
| "Vi bruger allerede Resight" | "Brug begge i 6 mdr gratis. Resight er stadig din research-platform; Metis er din monitor." |
| "Vi har en intern løsning" | "Lad os benchmark mod jeres interne. Hvor lang tid tager det at gennemgå 50 ejendommes tinglysning manuelt hver mandag?" |
| "GDPR — kan vi følge personer?" | "Ja, men kun navn-baserede + CVR-roller. CPR-adgang kræver kontraktlig hjemmel." |
| "Pris er for høj" | "DKK 30K/bruger/år. Sammenlign mod 1 manual times pant-monitorering pr. uge × 47 uger × 600 DKK = 28K. Plus refinansierings-prospect-værdi." |
| "Vi venter et år og ser om Resight tilføjer det" | "Resight har 6 år ikke prioriteret det. Standout-deal vil flytte fokus til international ekspansion. Sandsynligheden er <30%." |
| "Vi vil have on-prem" | "Roadmap: white-label/on-prem efter 5 enterprise-kunder kører cloud-version stabilt." |

### 7.2 Sandsynlige bekymringer

- **Datakvalitet:** Tinglysningsretten har ~3-5% fejlrate. Vi skal være transparente.
- **Latency:** Tinglysning → Metis-alert: hvor lang tid? Mål: <24 timer (daglig crawl).
- **Compliance:** Audit trail på hvem der kiggede på hvad. Skal logges.
- **Onboarding-friktion:** SSO via SAML for enterprise — roadmap.

---

## 8. Måle-rammer (KPIs)

| KPI | Mål uge 12 | Mål uge 26 | Mål uge 52 |
|---|---|---|---|
| Demos booked | 10 | 25 | 60 |
| Pilots signed | 3 | 6 | 15 |
| Paying customers | 0 | 3 | 12 |
| MRR | 0 | 75K DKK | 350K DKK |
| ARR | 0 | 900K DKK | 4,2M DKK |
| Cohort retention 6 mdr | n/a | 75%+ | 80%+ |
| NPS fra pilots | n/a | 30+ | 40+ |
| Cases publiceret | 0 | 2 | 6 |

**3-års-mål (uge 156):**
- 80+ paying customers (DKK 30K avg)
- 600+ paying users (DKK 30K avg)
- DKK 18M ARR
- 1-2 internationale piloter (Sverige/Norge)

---

## 9. Risici i GTM-spil

| Risiko | Sandsynlighed | Mitigering |
|---|---|---|
| Resight tilføjer mortgage-delta og rammer kreditor-segmentet | Medium | Vi skal LIVE inden 12 uger. Standout-deal flytter Resight's fokus til international. |
| ReData kopierer kreditor-pitch | Lav | De har bias-problem og mindre data-dybde. Reaktion ikke realistisk i 6 mdr. |
| GDPR-blokade på person-watching | Lav | Backup: roller + CVR-baseret. Stadig 80% af use case. |
| Pilot-friction: kreditorer vil ikke skifte | Medium | Pricing-modellen tillader parallel-kørsel; vi sælger ikke som "skifte" men som "supplement der bliver primær". |
| Manglende sales-kapacitet (Frederik bottleneck) | Høj | Hire 1 sales-person efter 3 pilots signed (Q4 2026 / Q1 2027). |
| Pricing er for høj | Lav | Volumen-rabat + add-on-modulær struktur giver fleksibilitet. |
| Resights kunder oplever priskrig fra ReData → flytter til ReData først | Lav | ReData er 6x dyrere end Resight, ikke billigere. Ingen priskrig. |
| Frankston-master integrations-friktion | Medium | Embedded mode er separate sprint efter pilot-validering. |

---

## 10. Konfidens-scores

| Aspekt | Score | Begrundelse |
|---|---|---|
| Markedsstørrelse 300-400 orgs | 80 | Konservativt, grounded i offentlige tal |
| Total ARR-pool DKK 26-134M | 70 | Bred range, afhænger af capture-rate + ARPU |
| 3-års konservativt DKK 9M ARR | 75 | Realistic men kræver pilot-momentum |
| Resight har ingen kreditor-vinkel | 90 | Verificeret via produkt-sider + transcript |
| Pricing-tier-struktur | 65 | Ikke benchmarket mod faktiske enterprise-deals |
| Pilot 1 (Draupnir) lukker | 95 | Aftalt på mødet |
| Pilot 2-5 lukker indenfor 12 uger | 55 | Outreach-arbejde ikke færdigt; segment ikke pre-warmed |
| Mortgage-delta som differentiator-moat | 88 | Stærk gennem T:497 + datadækning |
| Konference + content-strategi virker | 60 | Kræver eksekvering + sales-kapacitet |

**Samlet GTM-confidence:** ~72 — solid fundament, men eksekverings-risikoen ligger i sales-kapacitet (Frederik er bottleneck) og at vi rammer LIVE-vinduet før Resight reagerer.

---

## 11. Anbefalede beslutninger til Frederik (i morgen)

1. **Kreditor-positioneringen** — godkend "Kreditorernes Resight" som vores hovedvinkel før Sprint 0?
2. **Pricing-tier-struktur** — godkend 18K Pro / 30K Creditor / 25K volumen-rabat?
3. **Pilot-strategi** — godkend "9 mdr struktureret pilot med kvartals-feedback" for Rasmus?
4. **Sales-prioritering** — Frederik har ikke kapacitet til 50-person outreach + sprint-koordinering. Skal vi hire eller delegere?
5. **Content-strategi-start** — godkend at jeg eller Jens bruger fredage på blog-content?
6. **Næste pilot-prospect** — hvilken kreditor er nemmest at få et JA fra? Forslag: Faktorkredit (eksisterende Frankston-relation), eller via Mastercard-partner-channel et realkredit-institut.
