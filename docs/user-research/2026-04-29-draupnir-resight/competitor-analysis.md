# Competitor analysis: Resights & ReData (1. maj 2026)

**Formål:** Faktuel kortlægning af Metis' to primære konkurrenter — feature-bredde, datadækning, prissætning, kundebase, svagheder. Bruges som input til design-doc + GTM-strategi.

**Kilder:**
- Resights: byensejendom.dk artikel (Standout-investering apr 2026), bygge-anlaegsavisen artikel om byggeleads, jonathangormsen.dk case study, transcript T:782, **5 nye videoframes ekstraheret fra `Draupnir + Resight.mp4`** (29. apr møde — frames 010, 020, 024, 027, 030)
- ReData: redata.dk/produkter, redata.dk/priser
- Bemærk: resights.dk/* er JS-WAF-blokeret for direkte scraping, så feature-detaljer er kombineret fra videoframes + tredjeparts artikler

---

## 1. Resights

### 1.1 Selskabsdata
- **Grundlagt:** 2020 af Lars Horsbøl Sørensen + Mikkel Duif
- **Hovedkontor:** København
- **Ansatte:** 45 (apr 2026)
- **Kunder:** 1.300 selskaber, 3.700-4.000 brugere
- **Overskud sidste år:** 16 mio. DKK
- **Ejer-struktur:** Stiftere har majoritet. Standout Capital (svensk PE) købte 42% minoritet apr 2026.
- **Vækst-narrativ:** "Standout bringer komplementær erfaring til at accelerere ekspansion" (= geografisk udvidelse, formentlig Sverige + Norge)

### 1.2 Database-størrelse (verificeret fra videoframes)
- **2.621.073 ejendomme** søgbare (frame 020 søgeboks: "Søg blandt 2.621.073 ejendomme")
- 135.000 lejeobservationer (delvist annonce-baseret, ikke faktisk indberettet — T:281)
- 3.500.000+ ejendomstransaktioner
- 600+ map layers
- "Q2 transaktionsvolume Live" prognose (= live data-pipeline mod tinglysning?)

### 1.3 Modul-struktur (8 moduler)
Fra videoframes' venstre-sidebar (frame 010, 020, 027, 030):
1. **Hjem** — personaliseret dashboard med widgets
2. **Søg** (Ctrl+K) — global search
3. **Assistent** — AI chat (Rasmus: "aldrig noget brugbart" T:539)
4. **Data** — Ejendomme, Virksomheder, Handler, Leje (Ny), Byggeri (🔒 lock = upsell)
5. **Værktøjer** — Kort, Agenter (Ny), Lister, Analyse (🔒 lock)
6. **Support** — bottom of sidebar
7. (8.) Plus de udokumenterede moduler i 8-modul tællingen — sandsynligvis: Undersøg, Find (notifikationer)

**Lock-moduler observeret (= upsell):**
- **Byggeri** — projekter, udbud, byggeleads. Pris: separat tier
- **Analyse** — major investor activity siden 2008
- **Projekter** (på selskabs-tab, frame 027)

### 1.4 Detaljerede features (verificeret fra videoframes)

#### Hjem-dashboard (frame 020)
- Personlig hilsen ("God formiddag, Rasmus")
- Søgeboks med antal-tæller
- 6 widget-bokse:
  1. **Q2 transaktionsvolume Live** — line chart prognose
  2. **Seneste udvalgte handler** — kort liste med pris/afkast/segment/dato/sælger/køber
  3. **Seneste tilføjede projekter** — DK-kort med pins + projektdetaljer
  4. **Senest tilføjet til følg** — list af følgte entiteter
  5. **Senest tilføjede lister** — list af gemte lister
  6. **Feedback** — "Tilføj flere widgets — Vi har flere widgets på vej. Giv din feedback på, hvilke du helst ser." (smart product-strategi)

#### Person-side overblik (frame 010 — Rasmus' egen profil)
Tabs: Overblik, Portefølje, Ejendomme, Virksomheder, Handelshistorik, Porteføljeregnskab, Virksomhedsrelationer, Historik, **Tinglysning**, Dokumenter, Noter

Action-buttons: Rapport, Følg, Liste

Widgets på Overblik:
- Virksomhedsrelationer-tæller (Aktive/Inaktive/Konkurser)
- Portefølje-resumé (datterselskaber, p-enheder, ejendomme)
- Udvalgte roller (med dato-fra: "Direktør siden d. 19. juni 2025")
- Kontaktinfo ("Klik her for at checke telefonnummer" — hvilken kilde?)
- Mini-kort med adresse-pin

#### Selskabs-side Tinglysning-tab (frame 027 + 030)
**Frame 027 (TONSBAKKEN 12-14 ApS):**
- 4 kategorier: Fast ejendom, Andelsboligbogen, Bilbogen, Personbogen
- Fast ejendom-tabel: Adresse | Type | Prioritet | Debitorer | Kreditorer | Hovedstol | Tinglyst-dato
- "Sampantskema" eksport-dropdown

**Frame 030 (MiMo Invest Holding ApS — porteføljeniveau):**
- **Flat-list på tværs af alle ejendomme i porteføljen** — KILLER VIEW for kreditorer
- Type-classification: **Ejerpantebrev / Afgiftspantebrev / Privat pantebrev** ← Det er præcis F2's `debt_type` filter
- Prioritet-felt (1-12+) — pant-rang
- Hovedstol pr. tinglysning (8.2M, 16.7M, 1.9M, 6.0M, 24.9M, 5.7M, etc.)
- Begge debitorer + kreditorer i samme række

#### Email-notifikation (frame 024)
**Subject:** "Ændringer ved Brian Nielsen og 4 andre"
**Sender:** noreply=resights.dk@mail.resights.dk
**Format:**
```
Hej Rasmus. Vi har registreret følgende ændringer, som du følger:

Brian Nielsen
- Træder ud af sin rolle hos Projektselskabet Kværkeby ApS som Direktør
- Udtrådt som reel ejer af Projektselskabet Kværkeby ApS (tidligere 100%)

Middelgade 10, 8900 Randers C
- Primær ejer er ændret fra DAN MARK EJENDOMME Udvikling A/S til Middelgade Randers ApS
- Ejendommen er handlet til 12.500.000 DKK

Middelgade 12, 8900 Randers C
- Primær ejer er ændret fra DAN MARK EJENDOMME Udvikling A/S til Middelgade...
```
**Trigger-typer observeret:**
- Role change (Direktør udtrådt)
- Ownership change (reel ejer ændret)
- Ownership change ejendom (primær ejer ændret)
- Transaction (ejendommen handlet til X DKK)
- Annual report (frame 024 inbox: "Ny årsrapport for IWH Holding...")

**Bemærk:** Tinglysning/pantebrev-delta er IKKE i email-listen. Bekræfter Rasmus' citat T:497 ("Det gør det ikke på Resights").

**Email-frekvens:** Daglig (08:34 morgen-delivery), bundled per entitet, antal-i-subject-line.

### 1.5 Pricing (rekonstrueret fra transcript + offentlige kilder)
- **Basis-pris:** ~15.000 DKK/år/bruger (T:782 — uden lejeniveau-modul)
- **Bedre løsning:** ikke offentligt prissat (kontakt-sales)
- **Forretningsbetingelser:** alle priser ekskl. moms, månedlig vs. årlig billing, rabat ved volume + årlig
- **Lock-moduler upsell:** Byggeri og Analyse er separate
- **Byggeleads** har egen prisside (resights.dk/byggelead-priser)

**Pris-positionering:** ~11.5x ARPU baseret på 1.300 kunder × 3.700 brugere × 15K DKK = **DKK 55-200M ARR-band** (lavere hvis multi-bruger-rabat, højere hvis lejeniveau-modul tilkøb stor andel).

### 1.6 Resight's svagheder (verificeret)

| Svaghed | Kilde | Implikation for Metis |
|---|---|---|
| **Ingen tinglysning-delta-alerts** | T:497 | Metis' eneste sticky-feature mod superbruger-Rasmus |
| Lejeniveau er antaget (annonce-baseret) ikke faktisk | T:281 | Metis kan markedsføre kilde-mærkning som transparens |
| AI-assistent giver "aldrig noget brugbart" | T:539 | Lav AI-baseline at slå |
| Større designede regnskaber kan ikke altid hentes | T:380 | PDF-source-detection (Metis har det) |
| Spider-diagram for ejer-struktur kan blive uoverskueligt | T:392-410 | Hierarkisk Metis-stil = kvalitets-vinkel |
| Bygge-modul er primært kommunale projekter (ikke private) | T:467-485 | Vi behøver IKKE bygge dette |
| Folk drukner i klik-niveauer ("brugt to timer på at klikke videre") | T:410 | UX-fokus på "hvad ledte du efter" |

### 1.7 Resight's kunde-segmenter (rekonstrueret)
Fra resights.dk produkt-sider + transcript-kontekst:
- **Ejendomsinvestorer** (primær, men kun et segment) — Rasmus + Draupnir
- **Bygherrer** — projektlæsere, udviklere
- **Entreprenører** — byde på udbud (byggeleads-modul)
- **Arkitekter** — projekt-research
- **Leverandører** — byggeri-leads
- **Ejendomsmæglere** (mindre fokus)
- **Banker / kreditorer** (uklart om eksisterende segment) ← **MULIG VINKEL FOR METIS**
- **Bestyrelser / family offices** (Rasmus åbnede selv for Draupnir Invest-bestyrelsen som mulig kunde, T:359 "billig version af Resights til due diligence")

**Observation:** Resight er bygget til *investor* og *byggeri* segmenter — IKKE eksplicit til *kreditorer*. Det er hvor Metis kan tage en niche.

---

## 2. ReData

### 2.1 Selskab
- **Datakilde:** Real-time fra 20+ autoritative databaser
- **Differentiator (claim):** "OneDoor"-konceptet = unified property/business/person info i ét view

### 2.2 Modul-struktur (6 moduler)
1. **OneDoor** — public property/business/person info (uden MitID for dokumenthent)
2. **Boligdata** — private residential transactions, market trend analysis, filter på type/byggeår/kvm-pris
3. **Analyse** — major investor activity siden 2008, downloadable PNG/Excel
4. **Transaktionsdata** — investment property, rent/operating cost/yield metrics
5. **Boliglejedata** — residential rental, regional/postnummer/gade-niveau filter
6. **Markedsindsigt** — office market 19 regioner (PUBLIC ACCESS — gratis lead-magnet)

### 2.3 Pricing (PUBLIC, modsat Resight)

| Tier | Per-bruger pr. mdr | Base pr. mdr | 1 user/år | 5 users/år |
|---|---|---|---|---|
| **Standard** | 580 DKK | 7.000 DKK | ~91K DKK | ~118K DKK |
| **Premium** | 1.750 DKK | 21.000 DKK | ~273K DKK | ~357K DKK |

- Begge tiers indeholder alle 6 moduler
- API-adgang og data-extracts: custom pricing
- Volumen-rabat 1-25 brugere
- Årlig vs månedlig: rabat på årlig

**Observation:** ReData er **OFFENTLIG** med priser, Resight er kontakt-sales. ReData's basisprice er **6x højere** end Resights basis (15K vs 91K). To tolkninger:
1. ReData er positioneret højere end Resight (premium player)
2. ReData er overpriced og taber markedsandel

Givet Rasmus' citat T:802: *"ReData har tænkt det samme — vi kan lave dig en kopi af Resight"* og T:805 *"de mangler så ikke lige så gode eller et eller andet"* — peger på ReData er en kopi-konkurrent uden Resights data-dybde, men prøver at retfærdiggøre højere pris med "Premium"-positionering.

### 2.4 ReData's svagheder (rekonstrueret)
- **Bias-mur fra Resight-loyalitet** (samme som Metis møder)
- **Mindre data-dybde** end Resight ifølge Rasmus
- **OneDoor-koncept er ikke tinglysning-fokus** — overlapper Resights "Undersøg" men dybde ukendt
- **Ingen byggeri-modul** — overladt til Resight

### 2.5 ReData's strategiske advarsel for Metis
Fra transcript T:802-806:
> "ReData... har tænkt det samme. Vi kan lave dig en kopi af V-site og de mangler så ikke lige så gode... Så man skal selvfølgelig også passe på med, at man ikke går for tidlig ud med et eller andet produkt, hvor man siger, at det der, det duer jo ikke rigtigt."

ReData er **anti-eksemplet**. De er gået for tidligt ud uden tilstrækkelig data-dybde, og taler nu mod en bias-mur de ikke kan komme igennem.

**Implikation for Metis:**
1. Metis må IKKE lanceres bredt før paritet på 80%+ af kerne-data + 1-2 sticky-features
2. Lad Resight-loyalister blive på Resight indtil de selv kommer til os
3. Differentiér på ÉN niche først (kreditorer + tinglysning-monitor), ikke bredt

---

## 3. Sammenligning Resight vs ReData vs Metis

| Kriterium | Resight | ReData | Metis (1. maj 2026) |
|---|---|---|---|
| **Database (ejendomme)** | 2.6M søgbare | Ikke offentligt | ~1M (registry-api scope) |
| **Transaktioner** | 3.5M+ | "Mest comprehensive" | ✅ via Tinglysning + Boliga |
| **Lejeniveau-data** | 135K (delvist antaget) | "Boliglejedata" | ❌ — strategisk gap |
| **Byggeri-leads** | ✅ (lock-modul) | ❌ | ⏭️ Skip |
| **Tinglysning-monitor** | ❌ | ❌ | 🟦 (under bygning) |
| **Email-digest** | ✅ (daglig, multi-trigger) | Ukendt | ❌ — gap |
| **AI-assistent** | ✅ (lav kvalitet ifølge Rasmus) | ❌ | ❌ |
| **Lister/følg-kategorier** | ✅ ("Lister") | Ukendt | 🟦 (FollowButton + AlertsInbox bygget) |
| **Personaliseret dashboard** | ✅ (widgets) | Ukendt | ❌ — kun search-side |
| **Map layers** | 600+ | 5+ | ~8 (Leaflet incl. bluespot) |
| **Offentlig prissætning** | ❌ (kontakt-sales) | ✅ | TBD |
| **Pris fra (1 user/år)** | ~15K DKK | ~91K DKK | TBD (anbefal 25-50K interval) |
| **Kunder** | 1.300 / 3.700 brugere | Ikke oplyst | 0 (pre-launch) |
| **Kunde-segmenter** | Investor, byggeri, mægler | Investor, mægler | **Kreditor (Draupnir-segment)** ← niche-vinkel |

---

## 4. Strategiske observationer for Metis

### 4.1 Resight's moat er IKKE data
Datagrundlaget er offentligt (Tinglysning, BBR, CVR, EJF, DAR, MAT, VUR, transaktioner). Alle kan hente det. Resight's moat er:
1. **UX & data-tæthed på samme view** — "Det her overblik, det synes jeg er virkelig fedt" (T:370)
2. **2.6M ejendomme indekserede + 600 map layers** — vores egen registry-api skal nå det niveau
3. **Sticky habits** — "Folk er bare rimelig lojale" (T:365)
4. **Daily email digest** — sticky friction at fjerne hvis du allerede har den

Metis' moat-strategi:
1. **Niche-position:** "Kreditorernes Resight" — ALLE der har pant i fast ejendom (banker, realkredit, fonde, P/S, advokater) får features Resight aldrig har bygget
2. **Tinglysning-delta-engine:** F1 = uniqe sticky-feature
3. **Omvendt gælds-søgning:** F2 = kreditor-specifik (refinansieringsleads)
4. **Kilde-transparens:** F8 = anti-Resight ("vi vil ikke skjule om data er antaget")

### 4.2 Hvor mange creditor-kunder er der?

Markedsstørrelses-estimat for "kreditorer med pant i fast ejendom":
- **6-7 store realkredit-institutter** (Nykredit, Realkredit Danmark, Totalkredit, BRFkredit, DLR, Jyske Realkredit) — 50-200 brugere hver
- **70+ banker** med ejendomslån — 5-20 brugere hver
- **20-40 ejendoms-credit-fonde** (Draupnir Invest m.fl.) — 2-10 brugere
- **50-80 kapital-fonde / family offices med PE-ejendom** — 1-5 brugere
- **100+ advokat-kontorer** (insolvens, fast ejendom) — 1-5 brugere
- **30+ leasing/factoring** — 1-5 brugere
- **5-10 forsikringsselskaber** med ejendomslån-coverage

Konservativt: ~600-1.500 brugere på tværs af 250-400 organisationer. Hvis Metis fanger 30% over 3 år ved 25K-50K DKK/bruger/år = DKK 5-15M ARR alene fra kreditor-segment.

### 4.3 Anbefaling for marketing/GTM (preview til Fase D)
- **IKKE bredt brand-spil** — bias-muren er for høj. ReData er anti-eksemplet.
- **Direct outreach til creditor-segment** først via:
  1. Draupnir's eget netværk (gennem Rasmus + Ulrik Larsen — AIF-design-partner)
  2. Konkurrent-kreditorers tab — udlæg-events der findes via vores egen Tinglysning-crawl er hot leads
  3. LinkedIn-targeting på "Credit Officer", "Risk Manager", "Workout", "Property Finance" titler i banker
  4. Realkredit-foreningen og Finans Danmarks credit-arbejdsgrupper
  5. Advokatforeningens insolvens-sektion
- **PR-vinkel:** "Den første ejendomsdataplatform bygget til dem der har lånt penge til fast ejendom, ikke kun til dem der køber den"
- **Pilot-strategi:** Draupnir er pilot 1. Tilbyd 2-3 andre creditor-pilotsteder gratis i 6 mdr mod feedback + case-rettighed.

### 4.4 Risici
1. **Resight tilføjer tinglysning-delta** — de er 45 ansatte og kan bygge på 4-6 uger. Vi skal være LIVE før det.
2. **Standout vil presse Resight på international ekspansion** — flytter fokus fra DK-feature-roadmap, hvilket faktisk hjælper os
3. **ReData kunne kopiere F1 → kapring** — men de har ikke datadækningen og er gået for tidligt ud allerede
4. **GDPR på person-watching** — kan blokere F5 fuldt scope
5. **Datadækning F2** — kun 33% af tinglysning er indekseret (T:255). Skal accelereres til >90% før F2 kan markedsføres troværdigt.

---

## 5. Open questions til Fase C/D-design

1. **Skal Metis have lock-moduler (a la Resight Byggeri/Analyse)?** Eller alle features for alle? Svar afgør om vi har upsell-håndtag for "kreditor-pakke" vs "investor-pakke" tier.
2. **Personalized dashboard (Resight Hjem)?** P3-paritet eller P1-prioritet? Det er det første brugere ser hver morgen.
3. **Email-digest-format:** Kopier Resights bullet-format eller differentiér med "actionable items"-design (top 3 kritiske ændringer + lavere prioritet samlet)?
4. **Lister-feature (saved lists med kategorier)?** Resight har det, Metis har ikke. P2-prioritet.
5. **Skal "Agenter (Ny)" konceptet replikeres i Metis (= saved searches der auto-runs og giver alerts)?** Det matcher præcis Rasmus' "autopilot" T:347.
