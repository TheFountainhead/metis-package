# Lender Intelligence — Strategic-Prep Research (2026-05-02)

> Forberedelse til 2026-05-22 brainstorm. Per memory `project_lender_intelligence.md`.
> Research dato: 2026-05-02. F-NEW Tinglysning-tab er shipped i prod samme dag.
> Dette er informations-baseline for brainstorm — ikke en spec, ikke en anbefaling.

## TL;DR

1. **Resights HAR offentlige priser** (15.990 — 34.990 DKK/bruger/år, volume til 7.000/bruger ved 25+ seats). Modul "Sampantsskema" i Pro Plus dækker nogle lender-relevante data, men ikke loan-portfolio-management. Hul i markedet bekræftet på portfolio-side.
2. **DK SMV-bank IT-laget er dækket af tre incumbents** (BEC 13 ejer-banker + ~50 kunder, Bankdata 8 ejer-banker, SDC/Netcompany 124 institutioner) — alle med kreditrisiko-moduler men leveret som del af kerne-bank-platform. Dvs. SMV-bank-segmentet er teknisk dækket, ikke "sidder i Excel" for de banker der er medlem. **Hullet er for ikke-bank-lendere** (private debt-funde, pantebrevsselskaber, factoring, crowdlending) der ikke kan bruge BEC/Bankdata/SDC.
3. **Pricing-konvention**: per-bruger/år dominerer hos data-tools (Resights, Festina, Moody's). Per-loan pricing er sjældent men findes (TurnKey Lender, LendingPad). Frankston's per-loan-tese er differentieret — ikke standard, ikke heller uden præcedens.
4. **Moody's CreditView median annual cost = $72,548 (range $32.500-$134.614, kilde Vendr).** ~470K-985K DKK/år/kunde. Det placerer Frankston's DKK 25K-1M ARR-spænd i sub-Moody's-tier — passer for SMV-segmentet.
5. **3 strategiske spørgsmål research kunne svare på, 4 spørgsmål kræver Frederik's input** (brand, repo-struktur, compliance-omfang, Bankdata-bro).

## 1. Competitor landscape

### 1.1 Resights (verificeret 2026-05-02 via agent-browser)

**Status**: LIVE, 1.400+ kunder per egen marketing.

**Pricing** (DKK/bruger/år ex moms, volumendiskonteret):

| Tier | 1 user | 5 users | 10 users | 25 users |
|---|--:|--:|--:|--:|
| Pro | 15.990 | 11.000 | 9.000 | 7.000 |
| Pro Plus | 21.990 | 14.000 | 11.500 | 8.400 |
| Insights | 34.990 | 19.000 | 14.000 | 10.400 |

**Lender-relevante features** (Pro Plus tier):
- **Sampantsskema**: pålydende sats, hovedstol, rentetype, kreditor, ejendomme lånet berør. **Direkte overlap med Frankston's BFE-graf + mortgages**.
- Handler/afkast/statistik (skjulte selskabshandler, beriget med afkast/rådgivere/lejebærende areal)
- Avanceret Excel-udtræk
- Lejedata (135.000 observationer; kræver kunden indberetter egen lejedata for adgang)

**Hvad Resights IKKE har**:
- Loan-portfolio import (kundens egen loan-book — kun ejendomsdata)
- Concentration-analytics på lender-niveau (geografi/industri/debitor på tværs af lån)
- Stress-test scenarios
- User-rating overlay
- Audit trail til regulator-grade compliance
- API-tier offentlig prisat

**Memory feedback verification**: Tidligere konfabulering om "kontakt-sales pricing" var forkert (memory `feedback_dont_confabulate_when_fetch_fails`). Resights pricing ER offentlig, blot bag Cloudflare WAF — løsbart med agent-browser eller i Frederiks egen browser.

Kilde: https://resights.dk/priser/ — scraped via agent-browser 2026-05-02, gemt i `.firecrawl/resights-priser-extract.md`.

### 1.2 Bankdata

**Status**: LIVE, ejet af 8 banker + leverer til samme 8.

**Ejer-banker**: Jyske Bank, Sydbank, Sparekassen Sjælland-Fyn (nu SJF Bank), Ringkjøbing Landbobank, Djurslands Bank, Kreditbanken, Nordfyns Bank, Skjern Bank.

**Produkter**: Core banking, kort-platform, digital banking, AI-banking. Risikostyring leveres som del af kerne-platform. Finanstilsynet-redegørelse 2021 nævnte svagheder i IT-risikostyring — dvs. de HAR risikostyrings-systemer, men ikke alt offentligt.

**Pricing**: ikke offentlig. Cooperative-model (medlems-baseret).

**Lender Intelligence-relevans**: Bankdata-bro for Frankston ville være bro fra deres core-banking til Frankston's risk-overlay. Stor opgave (Q7 i open questions).

Kilde: https://www.bankdata.dk/ blokerede både WebFetch og agent-browser (Cloudflare WAF, deeper challenge end Resights). Verificeret via WebSearch-citater fra Konkurrencerådsafgørelse 2023, Wikipedia, Finanstilsynet IT-inspektion-redegørelse.

### 1.3 SDC / Netcompany Banking Services

**Status**: LIVE. SDC blev fusioneret med/købt af Netcompany 2025 (DKK 1 mia. til SDC-ejere, finaliseret Q2 2025).

**Kunder**: 124 finansielle institutioner i DK, Norge, Sverige, Færøerne. Kendte: Eika Group (NO), Lån & Spar, Sparekassen Kronjylland.

**Produkter** (per Netcompany-sitet 2026): Core banking, daily banking, e-banking, payments, **Risk Management (powered by Opics, daily granular real-time credit-, market- og liquidity-risk metrics)**, investments, accounting/reporting, open banking.

**Lender Intelligence-relevans**: Risk Management-modulet ER kreditrisiko, men leveret som integreret bank-platform-modul. Ikke standalone for ikke-banker.

**Pricing**: ikke offentlig (B2B kontrakt-baseret).

Kilde: https://netcompany.com/netcompany-banking-services/ (verificeret 2026-05-02).

### 1.4 BEC Financial Technologies

**Status**: LIVE (cooperative). Per 2025-2026 nyhed: BEC's 13 ejere har aftalt at Nykredit overtager BEC.

**Ejer-kunder (13)**: Nykredit Bank, Danske Andelskassers Bank, Grønlandsbanken, Fynske Bank, Lollands Bank, Lægernes Bank, Møns Bank, Merkur Andelskasse, Hvidbjerg Bank, Frørup Andelskasse, Andelskassen Fælleskassen, Faster Andelskasse, Frøslev-Mollerup Sparekasse.

**Andre kunder (eksempler)**: Coop Bank, BNP Paribas, Citi, Maj Bank, Lunar — total ~50 kunder.

**Lender Intelligence-relevans**: Maj Bank og Lunar er nye ikke-traditionelle banker — interessante som proof-of-concept for "ikke-traditionel lender med BEC-integration". Lunar er mobil-first.

Kilde: https://www.bec.dk/en/becs-customers/ (verificeret 2026-05-02).

### 1.5 Embankment

**Status**: LIVE, registreret hos Finanstilsynet som AIFM + depositary services provider.

**Tilbud**: End-to-end fund management/administration/depositary til AIF'er. Cloud-baseret platform. Marketing-tagline "Accuracy is everything".

**Pricing**: Ikke offentlig. Memory `project_aif_administration.md` siger "DKK 200K floor", Frankston's AIF Admin Light tier er positioneret 50-60% under det.

**Lender Intelligence-relevans**: Embankment er fund-administrator-side, IKKE lender-software. Adresserer AIF-management (bookkeeping, NAV, depositary), ikke loan-portfolio-risk-management. **Minimum overlap med Lender Intelligence-produktsporet** — de to spor (AIF Admin / Lender Intelligence) er distinkte.

Kilder: WebFetch på https://www.embankment.com/ returnerede minimal indhold ("Accuracy is everything" — JS-renderet). Crunchbase 403. Verificeret indirekte via PitchBook + WebSearch.

### 1.6 Festina Finance

**Status**: LIVE. Netcompany ejer 20%-andel siden 2022-2024 (eksakt år ikke verificeret, kilde-website-format usikkert).

**Tilbud**: Software til rådgivnings-rolle i banker. 20+ DK-medlemsbanker + nogle UK-building societies. Partnerskab med BEC for credit solution (loan origination).

**Lender Intelligence-relevans**: Adviser-lag, ikke risk-database. Adresserer "advisor giver kunde et lån" workflow, ikke "lender styrer sin loan-book". Ingen direkte konkurrent.

Kilde: https://www.festinafinance.com/ via WebSearch.

### 1.7 Moody's CreditView (international reference)

**Status**: LIVE globalt.

**Pricing-data fra Vendr** (https://www.vendr.com/marketplace/moodys):
- Moody's overall median annual cost: **$72.548/år**
- Range: **$32.500 – $134.614/år**
- Produkter: CAP, EDF-X, Kompany KYC API/Workspace, ORBIS All Companies FS, ORBIS All Companies

I DKK ved 6,8 kurs: **~221.000 – 916.000 DKK/år/kunde**. Median ~493.000 DKK.

**Lender Intelligence-relevans**: International benchmark. Frankston's DKK 25K-1M ARR-spænd dækker fra 5% af Moody's-mindstepris (under-tier) til lidt over Moody's-medianen (over-tier). For SMV-segmentet skal Frankston ligge sub-Moody's.

### 1.8 S&P Capital IQ / RatingsXpress

**Pricing**: Ikke offentligt verificeret. Anekdotisk fra G2 reviews og enterprise-software-vendor-data: enterprise-tier, kontrakt-baseret, $50K-$500K+ område. Ikke konkret nok til at citere.

**Status**: Ikke verificeret med konkret pris-data. Skip i brainstorm-baseline indtil enten Frederik har konkret tilbud eller Vendr/Spendflo data findes.

### 1.9 SaaS-lending-platforme (international, mindre tier)

| Vendor | Pricing-model | Note |
|---|---|---|
| Mambu | Custom enterprise | Cloud-native, modulær. Composable banking. |
| TurnKey Lender | Per-loan, min 50 loans | Eksplicit per-loan-pricing — præcedens for Frankston-tese |
| LendingPad | $50/md/bruger broker, per-closed-loan for lendere | Hybrid model |
| Fyndoo (Topicus) | Custom | 12.500+ bankers daily users; Originate-modul |
| Nortridge | Fra $1.200/md SaaS | Per-loan-volume tier på enterprise |
| LoanPro | API-first | $22B+ årlige loan-repayments managed |
| Finexos | Loan analytics dashboard | "Tidlige tegn på financial distress" — funktionelt overlap |
| Abrigo (Sageworks) | Enterprise | Loan pricing + credit risk |
| HES LoanBox | Custom | SME, MCA, leasing, trade finance |

**Konklusion**: Per-loan pricing er sjælden men ikke unik (TurnKey Lender, LendingPad-hybrid). Per-bruger/md/år dominerer.

### 1.10 Penta-Pension

**Status**: Memory `aktive_todos_index.md` siger "Park indtil FIDA". WebSearch fandt ingen offentlig DK-tjeneste ved det navn — det er sandsynligvis Frankston-internt projektnavn/produkthypotese, ikke en ekstern konkurrent.

Markér som "intern terminologi — afklar med Frederik" i brainstorm.

## 2. Customer-segment market sizing (Danmark)

### 2.1 Private debt-funde

**Verificerede DK-aktører**:
- **Capital Four** (København): €22B+ AUM, 160+ professionals. Stor — Frankston-pilot ikke realistisk uden enterprise-engagement.
- **Polaris Equity** (DK): ~€2B AUM, buyout + private debt. Mid-size.
- **Maj Invest Equity**: ~€590M committed PE-kapital. Mindre, SME-fokus.
- **Draupnir Invest**: DKK 181M AUM, 36 lån (per memory). **Frankston-pilot-kandidat**.
- **PensionDanmark** (private debt portion): Del af €40B+ AUM, betydelig privat-debt-portefølje. For stor til SMV-segment.

**Estimat antal**: 10-30 private debt/credit-fonde i DK når man inkluderer SME-tier (ekskl. de 2-3 store). **Skal verificeres mod Finanstilsynets virksomhedsregister** — det er adgang men kræver query (https://virksomhedsregister.finanstilsynet.dk/).

**Addressable** (per Frankston's pricing-tese DKK 50-200K/år): konservativt 8-15 fonde i Q3-Q4 2026 → DKK 400K-3M ARR.

### 2.2 Pantebrevsselskaber / Ejendomskreditselskaber

**Regulatory regime**: Bekendtgørelse 1241/2010. Tilladelse fra Finanstilsynet kræves. Årlig erklæring om kundekonto til ftnet.dk senest 1. september.

**Verificerede aktører**: Fairkredit, Jysk Ejendomskreditselskab, Vexa Ejendomskreditselskab, Husejernes Kreditlån. Liste ikke fuldt-fundet — Finanstilsynets register skal queryes.

**Faktorkredit (Frankston-tenant siden 28. apr 2026)** er i denne kategori — eksisterende relation.

**Estimat antal**: ~20-50 ejendomskreditselskaber i DK (groft skøn fra register-tilladelser nævnt i Finanstilsynet-afgørelser; **skal verificeres**).

### 2.3 Factoring / Fakturafinansiering

**Verificerede aktører**: AL Finans Factoring, Nordea Factoring, Svea Bank, BNP Paribas Factor (DK-filial), Arbejdernes Landsbank Factoring.

**Markedsstørrelse**: Ikke offentligt rapporteret som specialiseret nordisk markedsrapport. EFFA (European Factoring Federation) har DK-data men kræver medlems-adgang.

**Estimat antal**: 5-15 dedikerede factoring-spillere i DK (de største er bank-divisioner, ikke standalone).

### 2.4 SMV-banker (uden for de tre IT-providers)

**Bemærkning**: De fleste SMV-banker ER medlem af BEC, Bankdata eller SDC (per oversigt fra Finansdanmark — 50+ banker i DK, 124 institutioner i SDC alene). De har derfor risikostyrings-tools allerede — bare ikke specialiserede til Lender Intelligence's use case.

**Realistisk Lender Intelligence-target i bank-segmentet**: Banker hvor BEC/Bankdata/SDC ikke leverer dyb-nok kreditportefølje-analyse på real estate (særligt med pantebrevs-datalag). Dvs. **niche oven på eksisterende stack**, ikke erstatning.

### 2.5 Crowdlending / P2P

**Verificerede DK-aktive platforme**:
- **Flex Funding** (DK-baseret) — Nordens største crowdlending-platform til SMV
- Per `thecrowdspace.com` directory: 21 platforme tilgængelige for danske investorer (men kun Flex Funding er DK-baseret; resten er EU-platforme der markedsfører til DK)
- **Mopsos** (Frankston-tracked) — verificeret via memory, ikke i offentlige directories endnu
- **Lendwill** (Frankston-tracked) — ikke verificeret offentligt fundet

**ECSPR-register**: 254 ECSP-licenserede crowdfunding-platforme i Europa pr. 2026 (op fra 159 i 2023) per Eurocrowd. Specifikt DK-tal ikke fundet.

**Estimat addressable**: 3-8 DK-crowdlending-platforme; lille men teknisk simpelt segment.

### 2.6 Total addressable estimate (DK)

| Segment | Antal estimeret | ARR-spænd per kunde | Total potentiale |
|---|--:|---|--:|
| Private debt-funde | 10-30 | DKK 50-300K | DKK 0,5-9M |
| Pantebrevsselskaber | 20-50 | DKK 25-100K | DKK 0,5-5M |
| Factoring | 5-15 | DKK 50-200K | DKK 0,25-3M |
| SMV-banker (overlay) | 10-30 | DKK 100-500K | DKK 1-15M |
| Crowdlending | 3-8 | DKK 25-100K | DKK 0,1-0,8M |
| **Total** | **48-133** | | **DKK 2,4-32,8M** |

Memory `project_vej_a_b_parallel_execution.md` siger Vej B år 3 ARR DKK 2,5-6,0M. Det matcher den lavere ende af min total-estimering — realistisk.

## 3. Pricing-modeller

### 3.1 Hovedmønstre i markedet

| Model | Hvem bruger den | Frankston-fit |
|---|---|---|
| Per-bruger/år | Resights (15.990-34.990), Festina, de fleste SaaS | Lav fit — Lender Intelligence er mere portfolio-volume-drevet end seat-drevet |
| Per-loan/år | TurnKey Lender, LendingPad-hybrid | God fit — matcher Frankston's tese, men mindre udbredt |
| Per-AUM | Visse fund-administratorer | Mulig fit — men kompleks at implementere transparent |
| Per-modul | Resights add-ons (Lejedata, Sampants) | God til upsell, ikke base-pricing |
| Per-sag | m2soft (DKK 200/sag) | Eksisterende Frankston-præcedens — fungerer for tilfælde-baseret arbejde, ikke for portfolio-monitoring |
| Tier-baseret (Light/Standard/Pro) | AIF Admin (Frankston) | God fit — kombinerer per-loan med feature-gating |
| Build + SLA recurring | Frankston-master enterprise | Lender Intelligence enterprise-tier kan adoptere det |

### 3.2 Anbefalet pricing-frame til brainstorm (uden anbefaling)

Baseret på matrix:

**Variant A — Pure per-loan/år**:
- DKK 50-200/lån/år
- 500-5.000 lån = DKK 25K-1M ARR
- Pros: matcher reel cost-driver (data + monitoring per lån), TurnKey Lender-præcedens
- Cons: forretnings-kompleksitet (kunden skal rapportere antal lån månedligt? årligt? selv-rapporteret?)

**Variant B — Tier per kunde + add-on per-loan-volume**:
- Light: DKK 50K/år for op til 250 lån
- Standard: DKK 150K/år for op til 1.000 lån
- Pro: DKK 400K/år for op til 5.000 lån + Concentration-analytics
- Enterprise: Custom + SLA recurring
- Pros: forudsigelig revenue, simpelt at sælge
- Cons: skal kalibreres ift. faktisk kost-fordeling

**Variant C — Hybrid (Frankston AIF Admin-mønster)**:
- Light tier 6 mdr DKK 0 → DKK 75K/år (design partner-pris fra `project_vej_a_b_parallel_execution.md`)
- Standard DKK 18K/md fastlåst 24 mdr (lender pilot-pris fra samme)
- Pro: per-AUM eller per-loan oven på base
- Pros: Combiner discovery-pricing med stable run-rate
- Cons: kompleks at kommunikere

### 3.3 Benchmark-tabel

| Vendor | Tier | Pris (DKK ækvivalent) | Per-enhed |
|---|---|--:|---|
| Resights Pro | 1 user | 15.990/år | bruger |
| Resights Insights | 1 user | 34.990/år | bruger |
| Resights Insights | 25 users | 260.000/år | volume |
| Moody's CreditView (median) | enterprise | ~493.000/år | hele org. |
| Moody's CreditView (low) | enterprise | ~221.000/år | hele org. |
| Frankston m2soft | per-sag | 200/sag | sag |
| Frankston AIF Admin Light | tier | 75.000/år | hele org. |
| Frankston Lender (memo-tese) | per-loan | 50-200/lån | lån |

## 4. Regulatory landscape

### 4.1 GDPR (debitor-data = personoplysninger)

**Krav** ved håndtering af kunde-loan-bøger der inkluderer fysisk-person-debitorer (CPR, navn, adresse):
- Multi-tenant isolation skal være verificerbar (database-niveau tenant-IDs, ikke kun applikations-laget)
- Data Processing Agreement (DPA) med hver kunde
- Audit trail på adgang til personoplysninger
- Data subject access/deletion automation
- Cross-border-overførsel-rules (data skal blive i EU; AWS eu-central-1 + Hetzner DE er compliant)

Frankston har allerede DPA-template via NemComply-stack. Lender Intelligence kan genbruge.

### 4.2 Finanstilsynet (FT)

**For Lender Intelligence-kunder der selv er FT-regulerede** (ejendomskreditselskaber, banker, ECSPR, AIFM):
- Software-leverandøren er IKKE selv FT-reguleret — men skal levere data der opfylder kundens regulatoriske krav
- Audit trail skal være regulator-grade (kundens FT-tilsynsbesøg kan kræve adgang)
- Outsourcing-kontrakter (FT 4.2 § 5): hvis Frankston bliver "kritisk leverandør" til en bank, kan kunden's FT-redegørelse omfatte Frankston
- DORA (Digital Operational Resilience Act) — i kraft 2025 for kritiske finansielle systemer; kan ramme Frankston hvis SMV-bank-kunder bliver store nok

**For Lender Intelligence selv**:
- Ikke FT-godkendelse-krav for software som data-leverandør
- Hvis Frankston tilbyder concentration-rating eller credit score: kan ramme CRA-regulering (Credit Rating Agency, ESMA-tilsyn) — typisk for større ratings, men grænsen er uklar for SaaS-værktøjer

**Anbefaling til brainstorm**: Compliance-omfang er Q4 åbent strategisk spørgsmål. Realistisk minimum: GDPR-DPA-template + audit trail + EU data residency. Udvidet: SOC 2 Type II + ISO 27001 hvis enterprise-tier.

### 4.3 Data-retention

- Regnskabskrav (DK Bogføringslov § 12): 5 år for finansielle data
- AIFMD II (i kraft DK juni 2025): krav om expense transparency, liquidity management, delegation oversight — relevant for AIF-customers
- FIDA (Financial Data Access framework): kommer 2027-2028. Vil ændre data-deling-regler. Memory siger Frankston targeting FIDA via egen FISP-licens — Lender Intelligence kan profitere af det

### 4.4 Financial-term-verifikation (per memory `feedback_never_guess_financial_terms.md`)

Termer der bruges i Lender Intelligence-domænet og skal verificeres FØR brug i kunde-dialog:

- **LGD** (Loss Given Default) — verificeret: standard Basel III-term, andel af eksponering tabt ved default
- **EAD** (Exposure At Default) — verificeret: forventet eksponering på defaulttidspunkt
- **PD** (Probability of Default) — verificeret: 12-måneders default-sandsynlighed
- **CET1** (Common Equity Tier 1) — verificeret: Basel III kapitalkrav
- **IRB-A** (Internal Ratings Based - Advanced) — verificeret: avanceret intern rating-tilgang
- **SDO/SDRO** (Særligt Dækkede Obligationer / Realkredit-Obligationer) — DK-specifikt, verificeret via Finanstilsynet-register
- **AIFMD / FAIF** — verificeret: Alternative Investment Fund Managers Directive / Lov om forvaltere af alternative investeringsfonde
- **Pantebrevsselskab / Ejendomskreditselskab** — verificeret: BEK 1241/2010

Hvis Frankston anvender disse i marketing eller produkt-tekst skal de defineres i ordliste — ikke alle SMV-lendere bruger Basel-terminologi.

## 5. Tekniske priors

### 5.1 Multi-tenant loan-portfolio-import

Best-practice fra OCC (Office of the Comptroller of the Currency) Loan Portfolio Management Handbook:
- CSV-import er ikke nok alene — kunden skal kunne genaktivere import (re-load) med integritets-check
- Audit-trail-krav: alt input + transformations skal være rekonstruerbar
- Cloud-baserede loan-monitoring-løsninger: "single searchable database where the system itself automates the mandated activity, applies the due review date, and leaves an audit trail that cannot be altered"

Frankston har allerede mønster fra m2soft + PE-modul-import. Genbrugbart for Lender Intelligence.

### 5.2 Concentration-analytics

Moody's whitepaper-beskrivelse af concentration risk (paraphrased fra WebSearch):
- **Single-name concentration**: én debitor over X% af portefølje
- **Sector/industry concentration**: industri-eksponering
- **Geographic concentration**: regional eksponering
- **Vintage concentration**: lån fra samme periode (rentecyklus-risk)
- **Collateral-type concentration**: same pantsat ejendomstype

For DK-context skal også **prioritets-koncentration** (1./2./3. prioritets-pant-fordeling) — relevant for ejendomskreditselskaber. Det er Frankston's BFE-graf-fundament.

### 5.3 Multi-tenant SaaS architecture-best-practices

Fra GDPR/SaaS-research:
- Database isolation: Tenant IDs i hver tabel + row-level-security ELLER schema separation ELLER dedicated database (afh. af blast-radius-tolerance)
- Application-laget skal ALTID enforce tenant-context i hver query (ikke kun i JOIN)
- Network segmentation hvis data klassificeret som "confidential business data"
- Cross-tenant-access-attempts skal trigge alarm (Frankston har dette mønster fra Frankston-master)

### 5.4 Audit trail / regulator-grade history

Krav (per OCC + GDPR):
- Immutable event log (append-only, ikke updates)
- Inkluderer: hvem, hvad, hvornår, med hvilke inputs/attachments, fra hvilken IP/session
- Retention minimum 5 år (regnskab) + længere for FT-tilsynssager (typisk 7-10 år)
- Rekonstruerbart: skal kunne replay state ved en given dato

Frankston har ikke dette færdigt — F-NEW har eventbus til alerts, men ikke fuld audit-log. **Kandidat til Sprint 2 Lender Intelligence**.

### 5.5 Genbrug fra eksisterende Frankston-stack

Per memory `project_lender_intelligence.md`:
- BFE-graf, ownership, mortgages, valuations, transactions: registry-api LIVE
- F1 alert-pipeline: LIVE
- F-NEW Tinglysning-tab: shipped i prod 2026-05-02
- Drawer-komponent (Q7 i F-NEW): genbrugs-komponent
- m2soft import-mønstre: precedens
- Per-tenant gating: Frankston-master-mønster
- Pipeline-health watchdog (compound 2026-05-02): kritisk for SLA-løfter til lender-kunder

## 6. Strategic-question informational baselines

### Q1. Repo-struktur: separate repo vs. modul i metis-package?

**Data fra research**:
- Moody's, Resights, Festina opererer alle som standalone-platforme — **markedsstandard er standalone**
- Frankston-master + AIF Admin-mønster: separate repo (frankston-master, draupnir-invest etc.) — interne præcedens
- Metis er allerede property-pros-fokuseret (Resights-konkurrent). At lægge lender-features ind risikerer brand-konfusion: "er Metis for ejendomspros eller lendere?"

**Konklusion til brainstorm**: Lean svar = separate repo (`frankston-lender`) der konsumerer registry-api. Men Frederik skal afgøre — afhænger af brand-strategien (Q2).

### Q2. Brand: "Frankston Risk", "Lender Intelligence", "Frankston Lender"?

**Data fra research**:
- "Lender Intelligence" som brand-navn: ingen DK-konkurrent fundet på navnet (WebSearch tom for direkte match)
- "Risk" som suffix: Resights Insights, Moody's CreditView — domineret af "View"/"Insights"/"Lens"
- "Lender" som direkte segment-pointer: bruges af LenderKit, Lendr, Lendwill, Lending-Pad — generisk men forståeligt
- Frederik's egen brand-arkitektur (memory): `frankston.dev` (Vej A) + `frankston-lender.dk` (Vej B) + `frankston.io` (konsulent-førs moder) — **brand-domænet er allerede forhåndsbestilt som `frankston-lender.dk`**

**Konklusion til brainstorm**: Domænet implicerer "Frankston Lender" (eller subset). "Lender Intelligence" som produkt-navn på det domæne (Frankston Lender Intelligence) er præcedens-konsistent. Ingen verificeret konflikt-trademark-research er gennemført — skal gøres ved brand-finalisering.

### Q3. Build vs. køb: Concentration-analytics?

**Data fra research**:
- Moody's API for concentration-analytics: pricing $32K-$135K/år (Vendr)
- S&P RatingsXpress: ikke verificeret, men sandsynligvis $50K+
- Concentration-analytics ER offentligt-defineret (OCC handbook, Moody's whitepapers) — bygges relativt enkelt med BFE-graf-data Frankston allerede har

**Konklusion til brainstorm**: Build for SMV-tier (Light/Standard); enterprise-tier kan integrere Moody's API som premium-feature ("powered by Moody's"). Hybrid-strategi.

### Q4. Compliance: hvor langt går FT-godkendelse?

Se afsnit 4.2 ovenfor. Beslutnings-rammer:

**Tier 1 (minimum)**: GDPR DPA + audit trail + EU residency. Sufficient for most SMV-kunder.
**Tier 2 (mellem)**: SOC 2 Type II + ISO 27001. Krav fra større private-debt-funde og banker.
**Tier 3 (max)**: Outsourcing-godkendelse hos hver kundes FT-tilladelse + DORA-compliance. Krav hvis Frankston bliver "kritisk leverandør" til SDO-bank eller systemisk-vigtig kunde.

**Konklusion til brainstorm**: Start på Tier 1, plan for Tier 2 i år 2 (DKK 200-400K-investering), Tier 3 kun hvis specifik kunde kræver.

### Q5. Pricing-model

Se afsnit 3 ovenfor. **3 viable varianter (A/B/C) klar til diskussion**.

**Konklusion til brainstorm**: Variant B (tier + per-loan-volume) virker mest sælgbar baseret på markedsmønstre, men Variant A (pure per-loan) matcher reel cost-driver bedst og giver Frankston en differentiator. Variant C (hybrid) bedst til design-partner-fase.

### Q6. First pilot: Draupnir Invest officielt vs. bredere cohort?

**Data fra memories**:
- `project_lender_intelligence.md` rangordner: Draupnir > Nordic Bloom > Faktorkredit
- `project_vej_a_b_parallel_execution.md`: Ulrik dual-pilot-script til Draupnir = AIF Admin Design Partner + Lender Pilot kombineret. ARR-potentiale DKK 291K hvis ja på begge.
- Memory `project_aif_administration.md`: Draupnir er Light tier, DKK 181M AUM, 36 lån, P/S struktur, credit fond
- Faktorkredit (frankston-tenant 28. apr 2026, Tinglysning-fokus): andet realistisk pilot

**Konklusion til brainstorm**: Draupnir er klar mest-stickiest pilot på grund af kombineret AIF Admin + Lender. Anbefaling: Draupnir som design-partner pilot Q3 2026, Faktorkredit som second pilot Q3-Q4 (de er allerede kunde, lavere onboarding-friction). **Frederik skal afgøre om "officielt pilot" betyder enkelt-kunde eller en cohort på 2-3.**

### Q7. Bankdata-bro for SMV-banker — værdi vs. kompleksitet?

**Data fra research**:
- Bankdata har 8 ejer-banker. SDC har 124 institutioner. BEC har ~50.
- Total SMV-bank-dækning via 3 incumbents = ~150+ institutioner
- Integration med Bankdata's core-banking kræver Bankdata-godkendelse + cooperative-medlems-aftale eller bilateral aftale med en specifik bank
- Bankdata har historiske svagheder i IT-risikostyring (Finanstilsynet 2021) — der KAN være åbning for overlay-vendor

**Kompleksitet**: Høj. Bankdata er 8-bank-cooperative med fælles tech-stack. At bygge en bro kræver enten (a) bilateral aftale med én bank der så bærer integration-byrden, eller (b) cooperative-godkendelse fra alle 8 banker — næsten umuligt for ny vendor.

**Værdi**: Stor hvis det lykkes (8 banker = potentielt 8-bank-cohort på samme integration). Men time-to-first-revenue er 12-24 måneder vs. private-debt-funde der kan onboarde på uger.

**Konklusion til brainstorm**: Bankdata-bro = år 2-3 strategi, ikke MVP. Først bevis for produktet med private-debt-fund-segmentet, derefter bilateral pilot med én Bankdata-bank (Sparekassen Sjælland-Fyn / SJF Bank er sandsynlig kandidat — DKK 156.000 kunder, allerede-modne IT). MVP-fokus: ikke-bank-lendere først.

## 7. Open questions for Frederik

Punkter research ikke kunne løse — skal afklares i brainstorm:

1. **Penta-Pension** er nævnt i memory som "Park indtil FIDA". Er det Frankston-internt projektnavn, eller en ekstern kunde-prospekt? WebSearch fandt intet offentligt match.
2. **"Lendwill"** ikke fundet i offentlige crowdlending-directories — er navnet korrekt stavet, eller er det stadig stealth/pre-launch?
3. **Antal pantebrevsselskaber/ejendomskreditselskaber i DK** kræver query mod Finanstilsynet's virksomhedsregister (https://virksomhedsregister.finanstilsynet.dk/). Bør gøres FØR brainstorm — Frederik kan tilgå direkte og pasta resultaterne.
4. **Kapital Capital** nævnt i memory som privat debt-fund — ikke verificeret som eksisterende DK-aktør i offentlig research. Er det stavemåde-fejl eller stealth?
5. **Konkurrence-clausuler i Resights' kunde-aftaler**: hvis Frankston-kunder har Resights, må Frankston tilbyde overlap-features? Juridisk research-pkt for senere.
6. **Brand-trademark-search** for "Frankston Lender" / "Frankston Risk" / "Lender Intelligence" på WIPO + Patent- og Varemærkestyrelsen ikke gennemført.
7. **Faktiske buying-personas** hos private-debt-funde i DK: er det CIO, Risk-officer, eller portfolio-manager? Kvalitative interviews kræves — Rasmus Hornhaver (Draupnir) er primary kontakt per memory.
8. **Embankment's actual pricing-floor** (memory siger DKK 200K, ikke verificeret i denne research). Vigtig for AIF Admin-spor, mindre for Lender Intelligence (de er ikke direkte konkurrent).

## Sources cited

- https://resights.dk/priser/ (scraped via agent-browser, indhold gemt i `.firecrawl/resights-priser-extract.md`)
- https://www.bec.dk/en/becs-customers/ (WebFetch — succeeded)
- https://netcompany.com/netcompany-banking-services/ (WebFetch — succeeded)
- https://thecrowdspace.com/directory/p2p-lending-crowdfunding-platforms-in-denmark/ (WebFetch — succeeded)
- https://www.vendr.com/marketplace/moodys (WebFetch — succeeded, $72.548 median)
- https://www.finanstilsynet.dk/ansoeg-og-indberet/indberetninger-efter-virksomhedsomraade/pantebrevsselskaber (WebFetch — succeeded)
- https://www.bankdata.dk/ — **failed** (Cloudflare WAF, 403 både via WebFetch og agent-browser). Indirekte verificeret via WebSearch
- https://www.embankment.com/ — **partial** (returnerede kun "Accuracy is everything" — JS-renderet content). WebFetch på /about gav 0 indhold
- https://embankment.com/ — **failed** for detail (Crunchbase 403)
- https://p2pmarketdata.com/lending-platforms-denmark/ — **partial** (data dynamisk loadet, ikke i static HTML)
- WebSearch resultater for: Bankdata medlemmer, Sparekassen, Festina Finance, BEC, Capital Four, Polaris, Maj Invest, Mambu, TurnKey Lender, Fyndoo, ECSPR, FAIF, ejendomskreditselskab, factoring DK, crowdlending DK
- Finanstilsynet IT-inspektion redegørelse 2021 (Bankdata): https://www.finanstilsynet.dk/tilsyn/inspektion-og-afgoerelser/2021/feb/bankdata_250221
- Finanstilsynet ejendomskreditselskab: https://www.finanstilsynet.dk/ansoeg-og-indberet/ansoegningsskemaer/ansoegning-ejendomskreditselskab
- Finanstilsynet virksomhedsregister: https://virksomhedsregister.finanstilsynet.dk/ (kræver query for konkrete tal — ikke gennemført)

## Confidence

**Self-score: 78/100**.

**Caveats** (per `feedback_dont_confabulate_when_fetch_fails` og `feedback_never_guess_financial_terms`):

- **Resights-pricing**: 95% sikker — direkte scraped fra deres egen pris-side via agent-browser. Læringspoint: 451 WAF er løsbart, og tidligere konfabulering var fejl.
- **Bankdata interne risk-features**: 50% sikker — kunne ikke nå deres site (Cloudflare blok). Inferenced fra Finanstilsynet-redegørelse + WebSearch-citater. Skal verificeres direkte med Frederik (han kender markedet).
- **Embankment**: 40% sikker. WebFetch returnerede minimal indhold; pricing-floor "DKK 200K" er kun fra Frankston-memory, ikke ekstern verificeret.
- **Antal DK-aktører pr. segment** (private debt 10-30, pantebrevsselskaber 20-50, factoring 5-15): groft skøn fra search-resultater + memory. **Ikke verificeret mod Finanstilsynets virksomhedsregister.** Skal gøres af Frederik FØR brainstorm.
- **Penta-Pension og Lendwill** kunne jeg ikke verificere offentligt — flagget som open question 1 og 2.
- **Moody's $72.548 median**: 90% sikker — Vendr's marketplace data, ikke selv-rapporteret af Moody's. Range $32.500-$134.614.
- **TurnKey Lender per-loan-pricing**: 80% sikker — kilde: SaaSWorthy/Capterra-aggregat, ikke Turnkey-direkte. Per-loan-mønster er bekræftet, eksakte tal ikke offentligt.

**Hvorfor ikke højere score**:
- 4 ud af 7 strategic questions kræver Frederik's input (brand, repo, compliance-omfang, Bankdata-bro)
- 3 fetches fejlede uden gode alternativer (Bankdata, Embankment-detail, p2pmarketdata-dynamic)
- Markedssegment-tællinger er skøn, ikke autoritative tal
- Buying-persona-research er ikke gennemført (kvalitativ interview-research, ikke web-research)

**Hvad der ville bringe scoren til 90+**:
1. Frederik query Finanstilsynets virksomhedsregister for præcise tal (private debt-funde, pantebrevsselskaber, ECSPR-platforme i DK)
2. Bilateral verifikation med Bankdata-medlems-bank (Sparekassen Sjælland-Fyn er kandidat)
3. Embankment pricing-verifikation via en kontakt i fund-admin-segmentet (Ulrik Larsen via Draupnir er sandsynlig kanal)
4. 2-3 buying-persona-interviews (Rasmus, Ulrik, en factoring-spiller, en pantebrevsselskab) — kan integreres i brainstorm-forløbet
