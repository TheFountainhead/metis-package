# Feature implications for Metis

Konkrete user stories og prioriteret backlog udledt af user research med Rasmus Hornhaver (Draupnir) 29. apr 2026.

**Source attribution-format:** `T:linje` refererer til `transcript-excerpts.md`. Originalt transcript: `~/Dropbox/Frankston/Kunder/Investeringsrådgivere/Draupnir Investment Advisors A:S/Noter/Draupnir 2026.04.29.txt`

**Verificér mod Metis v2.6.0 før implementering** — flere features kan allerede være bygget. Status pr. 1. apr 2026 er i `~/.claude/projects/-Users-Frederik/memory/project_metis_standalone.md`.

---

## P0 — Sticky differentiator (skal bygges før bred lancering)

### F1: Alerts på gælds-ændringer pr. fulgt ejendom

**Som** ejendomsinvestor med "negative pledge" på udlån
**Vil jeg** automatisk få besked når der tinglyses nye ejerpantebreve, udlæg eller andre rettigheder på ejendomme jeg følger
**Så jeg** kan opdage default eller pantsætningsforbuds-overtrædelser samme dag de sker.

**Acceptance criteria:**
- User kan markere en ejendom (BFE-baseret) som "fulgt"
- Daglig delta-beregning på tinglysning vs. forrige dag
- Email-notifikation ved nye tinglysninger på fulgte ejendomme
- Notifikation viser: ejendom-adresse, hvad der ændrede sig (ny kreditor, hovedstol, retsanmærkning), link til detalje-side
- Differentiation mellem ejerpantebreve (low priority) og udlæg/retsanmærkninger (high priority)

**Datagrundlag:** Tinglysning er allerede del af Registry-API's wrap. Skal udvides med tidsserie/snapshot for at lave delta.

**Hvorfor P0:** Resight har det IKKE (T:497). Det er den eneste enkeltstående feature der kan motivere en superbruger som Rasmus til at skifte. Alt andet er paritet — dette er overlegenhed.

**Citat (T:503):** *"Det ville være genialt, hvis vi kunne se, at der lige pludselig kom et nyt ejerpand på her."*

---

### F2: Omvendt søgning på gæld

**Som** kreditgiver der leder efter dyre lån at refinansiere
**Vil jeg** kunne søge på gælds-attributter (rente over X%, gældstype, hovedstol-interval, geografi)
**Så jeg** får listet de ejendomme + ejere der matcher, så jeg kan kontakte dem proaktivt med tilbud om billigere finansiering.

**Acceptance criteria:**
- Filter-UI: gældstype (multi-select: ejerpantebrev, realkredit, privat pantebrev, etc.), rente-interval, hovedstol-interval, geografisk område, ejertype (selskab/privatperson)
- Resultatliste viser: ejendom-adresse, gælds-detaljer, ejerstruktur (link)
- Eksport til CSV/Excel
- Senere: gem søgning + få alerts når nye matches dukker op (= F1 + F2 kombineret)

**Datagrundlag:** Tinglysning + Registry-API. Frankston har estimeret 1.800 lån i nuværende dataset (ca. 33% af ejendomsmassen — fuld dækning på vej).

**Hvorfor P0:** Det er den feature Frederik live-demoede til Rasmus, og som Rasmus straks ville have. Findes ikke i Resight. (T:254-268)

**Citat (T:347):** *"Sætte en slags autopilot på, som bare tracker, når der kommer nye, giver mig en besked."*

---

## P1 — Paritet med Resight (skal være på plads før lancering)

### F3: Selskabsforside med portefølje-overblik

**Som** investor der researcher et nyt selskab
**Vil jeg** se et samlet overblik på selskabs-forsiden: nøgletal, nøglepersoner, portefølje-fordeling, kort over ejendomme
**Så jeg** kan vurdere selskabet på 30 sekunder uden at klikke videre.

**Reference:** screenshots/01-company-overview-regnskab.jpg, screenshots/05-portfolio-overview.jpg

**Acceptance criteria:**
- Forside med boxes: nøglepersoner, nøgletal (omsætning, balance, EK), portefølje-fordeling pie chart, kort over ejendomme
- Direkte links til regnskab, ejendomsliste, tinglysning, historik
- Match Resights informations-tæthed (se screenshot 05)

**Status (sandsynligvis dækket i v2.6.0):** Memory siger CVR-opslag inkl. virksomhedsinfo, regnskaber, roller, ejerstruktur, ejendomme er bygget. Verificér visuelt mod screenshot 05.

**Citat (T:370):** *"Det her overblik, det synes jeg er virkelig fedt. (...) Det her er det eneste jeg bruger."*

---

### F4: Regnskab som direkte PDF-download

**Som** investor
**Vil jeg** klikke på "regnskab" og få PDF'en direkte
**Så jeg** ikke skal navigere til Erhvervsstyrelsen.

**Acceptance criteria:**
- En-klik download fra selskabsside
- PDF-source-detection (struktureret data vs. designet PDF)
- Fallback til CVR-kald hvis lokal cache mangler

**Status:** Memory bekræfter PDF-source-detection er bygget i v2.6.0. ✓ Sandsynligvis done.

**Resight-svaghed (T:380):** Større/designede regnskaber kan Resight ikke altid hente — Metis kan adressere dette med bedre PDF-parsing.

---

### F5: Følg-funktion (selskab + ejendom + person)

**Som** bruger
**Vil jeg** følge entiteter på tværs af typer (selskaber, ejendomme, personer)
**Så jeg** får én samlet feed/digest med ændringer.

**Acceptance criteria:**
- "Følg"-knap på alle entitetssider
- Personlig følg-liste under user-profil
- Daglig/ugentlig email-digest
- Emnetyper: nyt regnskab, ejerskifte, ny tinglysning, rolle-ændring, navne-skifte
- For ejendomme også: F1 (gælds-ændringer)

**Citat (T:489):** *"Når jeg inde i min mail får (...) en reminder her på de her personer (...). Eller nogle firmaer, vi følger, nu er der kommet over support, siger regnskabet. Det er også ret fed."*

---

### F6: Ejendomsdetalje med "lignende handler" og skråfoto

**Som** ejendomsinvestor
**Vil jeg** se på ejendomssiden: BBR, vurderinger, lignende handler i området, skråfoto, matrikelkort
**Så jeg** kan vurdere ejendommens markeds-positionering.

**Reference:** screenshots/03-similar-trades-and-aerial.jpg

**Acceptance criteria:**
- Tabel med 5-10 lignende handler (samme kvm-interval, område, type) — kvm-pris, salgsdato, areal
- Skråfoto + matrikelkort side-om-side
- Link "Se alle i handelsmodulet"

**Status:** Adresseopslag inkl. BBR, vurdering, ejere, transaktioner er bygget i v2.6.0. Verificér om "lignende handler"-modul er der.

---

### F7: Person-søgning med ejer-roller + ejendomsmasse

**Som** investor der laver due diligence på en person
**Vil jeg** slå personen op og se: alle roller (CVR), alle ejede selskaber, alle ejendomme (også indirekte via selskaber)
**Så jeg** får komplet overblik over personens aktiver.

**Status:** Memory bekræfter person-søgning bygget med roller + ejede selskaber + ejendomsantal (147 ejendomme for Bisgaard-Frantzen). ✓ Sandsynligvis done.

**Citat (T:418):** *"Du kan søge på personer, ejendomme og virksomheder."*

---

## P2 — Bedre end Resight (kvalitets-vinkler)

### F8: Lejeniveau med kilde-mærkning

**Som** investor
**Vil jeg** se om et lejeniveau er FAKTISK indberettet eller ANTAGET fra annonce-pris
**Så jeg** ikke fejlagtigt antager Resight-data er faktuelle.

**Acceptance criteria:**
- Hver leje-data-punkt har badge: "Indberettet" / "Annonce-baseret estimat" / "Modelleret"
- Tooltip forklarer kilde-typen
- Mulighed for at filtrere visning til kun verificerede data

**Hvorfor P2:** Resights lejeniveau er delvist antaget, men det er skjult (T:281). Metis kan markedsføre transparens som differentiator i sales-pitch.

**Citat (T:281):** *"Når man lidt skjult finder ud af at det er sådan de gør det (...) det er det faktisk ikke."*

---

### F9: Hierarkisk ejer-visualisering (ikke spider)

**Som** investor der researcher ejer-struktur
**Vil jeg** se ejerstrukturen som hierarki (mor → datter → barnebarn) med kollapsbare grene
**Så jeg** ikke drukner i en uoverskuelig spider når en person har 50+ selskaber.

**Acceptance criteria:**
- Træ-visning med ekspander-/kollaps-knapper
- Vis kun primær gren by default — øvrige bag "vis flere"
- Kontrast: fede linjer for primært ejerforhold, tynde for indirekte
- Toggle mellem "spider" (Resight-stil) og "hierarki" (Metis-stil)

**Citat (T:410):** *"Så har de [Resight-brugere] brugt to timer på 'Nå, men så klikker vi videre' og så er de faktisk: 'hvad var det egentlig, jeg skulle finde'."*

---

### F10: Vis vurdering + tinglyst hovedstol side-om-side

**Som** kreditgiver
**Vil jeg** se ejendommens offentlige vurdering OG tinglyste hovedstol på samme view
**Så jeg** kan vurdere LTV med det samme uden at klikke videre.

**Acceptance criteria:**
- På ejendomsdetalje: "Vurdering 2024: 12 mio. DKK | Tinglyst hovedstol: 8 mio. DKK"
- Disclaimer: "Tinglyst hovedstol ≠ aktuel restgæld" (memorymemoir-tip fra Rasmus)
- LTV-indikator (low/medium/high)

**Citat (T:329):** *"I tingbogen, så kan et pantebrev godt have en hovedstol på 12 millioner, men der kun er gæld for 10."*

---

### F11: Køber-profil-analyse (ejerskifte → demografi)

**Som** ejendomsmægler
**Vil jeg** ud fra ejerskifte-historik se "hvem køber typisk denne type ejendom" (alder, geografi, evt. familie-status)
**Så jeg** kan målrette markedsføring og rådgive sælger.

**Acceptance criteria:**
- På ejendomsdetalje: "Sandsynlige købere: 35-45 år, fra Østerbro/Frederiksberg, par uden børn"
- Bygger på ejerskifte + folkeregister-flytte-historik
- Anonymiseret aggregat (ikke konkrete personer)

**Hvorfor P2:** Ikke kerne-målgruppen for Metis, men er en mulig B2B-vinkel mod ejendomsmæglere. (T:446)

---

## P3 — Skip / lav prioritet

### F12: Bygge-modul (offentlige udbud)

**Status:** Skip for nu. Rasmus tester det ikke værd. Mest kommunale projekter, ikke private. (T:467-485)

### F13: AI-assistent på toppen

**Status:** Lav forventning. Resights AI bruger Rasmus ikke ("aldrig noget brugbart" T:539). Metis kan parkere AI-features bag den nuværende søgning indtil F1+F2 er solide.

### F14: Lavere prissætning som primær differentiator

**Status:** Skip. Datagrundlaget er det samme (offentlig data). 25 % lavere pris er den-rabat der er aftalt for Rasmus, men det er ikke en moat. Moat er sticky-features (F1, F2). (T:782)

---

## Prioriteret roadmap-forslag

### Fase 1 — Lukke Resight-gap (verificér mod v2.6.0)
F3 (selskabsforside), F4 (regnskab-PDF), F5 (følg uden gælds-alerts), F6 (ejendomsdetalje + lignende handler), F7 (person-søgning) — det meste er sandsynligvis bygget.

### Fase 2 — Sticky differentiators (skal bygges)
**F1 (gælds-alerts)** + **F2 (omvendt søgning)** — det er disse to der gør Rasmus klar til at skifte. Build first, deploy as private beta til Rasmus.

### Fase 3 — Kvalitets-vinkler
F8 (lejeniveau-kilder), F9 (hierarkisk ejer-vis), F10 (LTV-side-om-side), F11 (køber-profil)

### Fase 4 — Lancering
Når F1-F10 er klar + Rasmus har valideret som superbruger → bred lancering, men <em>først</em> efter case-validering.

---

## Risici & lessons fra ReData

**Konkurrenten ReData** har lanceret en Resight-kopi for tidligt. Rasmus' tålmodighed med dem er kort: *"Man skal bare møde de mindste ting, som ikke er der, i forhold til det andet, og tænke, nej, fuck det, bliver jeg bare her"* (T:365). Frankston må ikke gentage den fejl.

**Konkret bar for bred lancering:**
- F1 + F2 LIVE (P0-features)
- Min. 90% paritet på F3-F7 (P1-features)
- Min. 1 superbruger (Rasmus) der har skiftet og bekræfter Metis er primær-værktøj — ikke bare supplement
- Maks. 5 åbne known-bugs der ikke matches af Resight

---

## Open questions til afklaring

1. **Datafrekvens for tinglysning:** Hvor ofte hentes nye tinglysninger fra Tinglysningsretten? Hvis det er døgnvis vs. timebaseret, har det betydning for F1's notification-latency.
2. **BFE-stabilitet:** Er BFE stabil nok til at bruge som "fulgt entitet"-key, eller skal vi følge på matrikel-niveau?
3. **Rasmus' superbruger-aftale:** Er der formel hensigtserklæring, eller er det ren parallel-kørsel? Påvirker hvor presset feedback-loopet er.
4. **Embedded vs. standalone roadmap:** Bygges F1+F2 i pakken (begge stier får dem) eller kun standalone først? Frankston-master kunder (Draupnir-master via embedded) skal også have gælds-alerts.
