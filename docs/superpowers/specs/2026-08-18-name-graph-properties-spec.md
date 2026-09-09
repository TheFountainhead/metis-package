# Ejendomme på navne-grafen — spec

**Dato:** 2026-08-18 · **Status:** afventer Frederiks review
**Udløst af:** Frederiks test 18/8 — navne-søgning på "Frederik Larnæs" gav ikke
det ejerskabs-/ejendomsbillede han forventede.

---

## 0. Det vigtigste først: rodårsagen var IKKE manglende ejendomme

Undersøgelsen 18/8 fandt **to uafhængige ting**, og kun den ene er en mangel.

**(1) E-mail-gaten stoppede opslaget, før det blev udført.** Målt i prod:
`METIS_GATING=true`, `free_lookups => 1`, `rate_limits.anonymous => 1/time`.
I `Search.php:253-262` `return`'es der FØR søgningen, når kvoten er brugt.
Frederik står ikke på `METIS_PILOT_USERS` (det gør derimod `rasmus@draupniram.com`
og `fl@inovabolighandel.dk` — sidstnævnte er **Frederik Larnæs selv**, user 4).
⇒ Han så forsiden med chips igen, ikke et resultat. **Dette er hele forklaringen
på den observerede adfærd.** Håndteres separat (pilot-token), ikke i denne spec.

**(2) Ejendomme via selskaber ER allerede i navne-grafen — de blev bare aldrig set.**
Kode-verificeret: `tickProperties()` (`PersonStructure.php:811`) er **ikke** gated
på `$source`; fase 3 er cvr-nøglet og kører også i name-mode.
`OwnershipGraphBuilder::buildForPerson()` fase 3 hænger dem på selskabsnoderne.

🚨 **Konsekvens for scope:** den oprindelige antagelse ("navne-siden mangler
ejendomslaget") er **forkert**. Specen 2026-07-28 havde det allerede i scope
(linje 3: "ejendomme via selskaber"), og det blev bygget.

**Det eneste der reelt er CPR-eksklusivt er `private_properties`** — personens
PRIVAT-ejede ejendomme (`mount()` sætter `layers = ['ownership','roles']`).
Det var et bevidst valg (2026-07-28 §75: "private ejendomme via navn" = out of scope).

---

## 1. Hvad denne spec så handler om

Ét spørgsmål: **skal privat-ejede ejendomme kunne vises ud fra et navn?**

Målt for testpersonen (prod, 18/8):

| Kilde | Antal |
|---|---|
| Ejendomme via hans 7 ejende selskaber | **30** (allerede synlige i grafen) |
| Privat-ejede i VORES data | **0** |

🚨 **RETTET 18/8 — de "0" er IKKE et faktum om virkeligheden.**
Frederik påpegede at Tingbogen viser private ejendomme for personen. Efterprøvet:

- **41,4 % af alle ejendomme (1.273.875) har ALDRIG fået hentet ejerskab**
  (`tinglysning_sync_attempts = 0`). Dækningen er ujævn: 2970 Hørsholm 54 %,
  8000 Aarhus 97 %. Se registry-api
  [#285](https://github.com/TheFountainhead/registry-api/issues/285).
- Tingbogen identificerer ejere på **navn + fødselsdato**
  (`PersonSimpelIdentifikator`). Personen har `birth_date = NULL` i `persons`,
  og hans CVR-adresse er **adressebeskyttet** (alle felter tomme undtagen
  `landekode: DK`) ⇒ koblingen CVR-person → Tingbogs-ejer kan ikke laves
  automatisk. De 5 Larnæs'er der ER i tinglysningsdata, har alle fødselsdato.
- 🪤 Men fødselsdato er ikke en absolut spærre: personer uden bærer 171.603
  ejerskabslinjer. Den fjerner det stærkeste matchkriterium, ikke muligheden.

⇒ **"0" betød "ingen linje i den del af Tingbogen vi har nået at hente"** — ikke
"ejer ingenting". Klassisk *tomt output ≠ rent resultat*. Forretnings-caset for
§2 kan derfor IKKE afvises med henvisning til denne person.

## 2. Beslutning der er BLOKERET PÅ FREDERIK

**Skal privat-laget åbnes for navne-opslag?** Tre veje:

**A. Behold som i dag (anbefalet indtil videre).** Privat-laget forbliver
CPR-eksklusivt. Noten "Søg med CPR-nummer for også at se personens private
ejendomme" står allerede i graf-sektionen. Nul arbejde, nul risiko.

**B. Åbn laget via navn.** Kræver et navn→privat-ejendom-opslag. `property_owners`
er polymorf (`owner_type='App\Models\Person'`, `owner_id` → `persons.id`), så
et navne-match ER teknisk muligt uden CPR.
🚨 **Men navn-alene er UNDER-diskriminerende** — to personer med samme navn
kollapser til én. Samme fejlklasse som NemComply #83 (MEMORY.md). En forkert
tilskrevet ejendom i due diligence er værre end en manglende.
⇒ Kræver disambiguering (fødselsdato/adresse), som i dag er out of scope (§75).

**C. Vis kun et ANTAL uden detaljer** ("Personen har N privat-ejede ejendomme —
søg med CPR for detaljer"). Lavere risiko end B, men arver samme
navnekollaps-problem på selve tallet.

>  **Anbefaling (revideret 18/8):** stadig **A nu** — men af en ANDEN grund end
> først skrevet. Ikke fordi laget er værdiløst (det argument byggede på de
> fejlfortolkede "0"), men fordi B/C kræver navne-match, og navn alene er
> under-diskriminerende. Dertil: så længe dækningen er 41 % udækket
> ([#285](https://github.com/TheFountainhead/registry-api/issues/285)), ville et
> nyt privat-lag arve præcis det problem det skulle løse — en tom liste ville
> stadig ikke betyde "ejer ingenting".
>
> ⇒ **Rækkefølge: #285 (dækningsforbehold) FØR B/C.** Uden det bygger vi en
> visning der kan lyve autoritativt. Revurdér B når både #285 og
> disambiguerings-pickeren (§75) findes.

---

## 3. Hvis B/C vælges — invarianter der SKAL holdes

Skrevet ud fra de fælder koden allerede er hærdet mod. Ingen af dem må brydes.

1. **null ≠ tom.** Et fejlet kald må ALDRIG rendere som "ingen ejendomme".
   `fetchPersonPropertyPortfolio()` skelner allerede fire udfald
   (`loaded|empty|not_found|failed|permanent`) — genbrug dem, opfind ikke nye.
   Falsk autoritativ benægtelse er den værste fejlmodus i due diligence.
2. **Ingen CPR i graf-payloaden.** Node-id'er, labels, kanter, DOM-attributter.
   Person-roden hedder `person:root`.
3. **Intet `#[Locked]` på `$source`.** Det brækkede CPR-siden i prod på fire
   minutter (Flare #9103976) — `#[Lazy]`-hydrering afvises af Locked.
   Tamper-stien lukkes med eksplicitte guards i hver public metode.
   **Ny public metode der rører privat-tilstand SKAL tilføje sin egen guard.**
4. **Egen cap.** `person_private_properties => 10` hænger på person-roden, ikke
   pr. selskab — genbrug ikke `properties_per_company`.
5. **Aldrig-tom-reglen.** Et chip-toggle der ville efterlade kun person-noden
   afvises helt (`toggleLayer()`).
6. **Dedup på BFE.** Backenden de-duplikerer allerede på `matrikel_id` på tværs
   af selskaber og tilskriver den mest specifikke ejer (`$seenBfes`,
   `CvrController:334-347`). Privat-laget må ikke genindføre dobbelttælling.

---

## 4. Test-krav (hvis B/C bygges)

🚨 Lært af tidligere falske grønne i netop denne komponent:

- **`Livewire::test()` beviser INTET om lazy-stien.** 509 grønne tests missede
  Locked-nedbruddet, fordi harness'en aldrig kører snapshot-hydreringen.
  ⇒ Kræv en test der går gennem den faktiske lazy-cyklus.
- **Muter koden og se testen fejle** — ellers er den vacuous.
- **`Http::preventStrayRequests()` + fake UDEN privat-mønster** til at bevise at
  endpointet ikke kaldes i de tilstande hvor det ikke må.
  🚨 IKKE `Http::assertNotSent` — kendt inert sammen med pool.
- **Regression:** `source`-default `'cpr'` ⇒ alle eksisterende CPR-tests grønne.
- **Se UI i browser før completion.** Kortet har før vist gammel tilstand mens
  toasten meldte ændring.

---

## 5. Eksplicit UDE af scope

Disambiguerings-picker · enhedsnummer-baserede URL'er · ændringer af
`PersonRoles`/`person-roles`-endpointet · finansdata i navne-payloaden ·
e-mail-gaten og pilot-tokens (separat spor) · rollefelt-rodet i
`searchDeltagereByName` (se §6).

---

## 6. Sidefund — i AKTIV brug · sag oprettet: registry-api [#284](https://github.com/TheFountainhead/registry-api/issues/284)

`CvrService::searchDeltagereByName()` returnerer **rod i rollefeltet** — datoer
og tal hvor rollen skal stå. Målt på alle 9 selskaber, fx Inova ApS:

```
[DIREKTØR | 2025-11-30 | 1.0 | 1.0 | 1 | 1 | Reel ejer | Har indirekte besiddelser]
```

🚨 **Det er disambiguerings-endepunktet** (`GET /v1/cvr/person-disambiguate`,
`CvrController:205-225`, cachet 24t) — og det er **i aktiv brug**:
`PersonFollowButton.php:71` → `RegistryApi::disambiguatePerson()`.
Det er 2-trins "hvilken person mente du?"-modallen (PR #55).

⇒ **Dobbelt betydning for denne spec:**
1. Roller vist i disambiguerings-modallen kan i dag indeholde tal og datoer
   i stedet for rollenavne — altså dårligere grundlag for at vælge rigtig person.
2. Vej B afhænger af netop denne mekanik. **Rettes den ikke, står vej B på et
   fundament der selv er defekt.**

Metis-GRAFEN bruger det ikke (den bruger `personCompaniesByName`, som
returnerer rene roller: `ceo`, `legal_owner`, `beneficial_owner`), så det
forklarer IKKE Frederiks oplevelse.

🪤 Rettes det: cachen er 24t og nøglet på navn+limit — gammelt rod overlever
et deploy. Ryd cachen, ellers ser du stadig de gamle værdier og tror fixet
virkede/ikke virkede på et forkert grundlag.
