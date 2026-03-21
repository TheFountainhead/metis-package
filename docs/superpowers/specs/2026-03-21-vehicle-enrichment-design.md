# Metis Vehicle Enrichment — Design Spec

**Date:** 2026-03-21
**Status:** Approved
**Author:** Frederik Nielsen + Claude

## Summary

Udvid Metis med køretøjsberigelse via Synsbasen API. Rådgivere tilføjer nummerplader → systemet beriger med teknisk data (mærke, model, km, syn) og Bilbog-data (pant, udlæg, ejendomsforbehold) via Synsbasens `tinglysning_data` expansion. Ét API-kald giver begge datatyper.

## Motivation

Investeringsrådgivere og långivere har behov for et samlet formuebillede inkl. køretøjer. Metis beriger i dag med ejendomme, virksomheder og persondata — køretøjer er et naturligt næste skridt. Særligt relevant for kreditvurdering, hvor køretøjsgæld og -aktiver er væsentlige.

## Datakilder

### Synsbasen API — Primær datakilde (teknisk + Bilbog)
- **Adgang:** REST API med API-nøgle, ny integration
- **Søgning:** Registreringsnummer (nummerplade) eller stelnummer (VIN)
- **Data (gratis opslag):** Mærke, model, variant, årgang, brændstof, km-stand, synshistorik, afgifter
- **Data (1 token/opslag via `expand=tinglysning_data`):** Pant, ejendomsforbehold, udlæg, kreditorer, debitorer, beløb
- **Pris:** Basic 250 kr/md (2.500 opslag + 150 Bilbog-tokens), Advanced 600 kr/md (12.500 opslag + 500 tokens)
- **Begrænsning:** Ingen reverse lookup (CPR/CVR → køretøjer). Kræver nummerplade eller VIN som input

### Manuel input — Rådgiver-drevet
- Rådgiver indtaster nummerplader de kender fra kreditansøgning eller kundedialog
- Beriges automatisk med teknisk data + Bilbog-pant via Synsbasen

### Fremtidige datakilder (parallelle spor)
- **DMR §17-ansøgning** (sendt til Motorstyrelsen) — hvis godkendt: automatisk CPR/CVR → alle ejede køretøjer
- **Tinglysningsretten** (mail sendt) — forespørgsel om direkte SSL-API til Bilbogen eller dataleveringsaftale. Hvis opnået: erstat Synsbasens tinglysning_data med direkte adgang (sparer tokens)

## Brugerflow

### Rådgiver-input (ved CPR/CVR-opslag i Metis)
1. Rådgiver slår person (CPR) eller virksomhed (CVR) op — alle eksisterende sektioner vises
2. Ny "Køretøjer"-sektion vises med "Tilføj køretøj"-knap
3. Rådgiver indtaster nummerplade → klik "Tilføj"
4. Registry-API kalder Synsbasen med `expand=tinglysning_data` → teknisk data + pant i ét kald
5. Køretøjet vises som kort med alle data
6. Rådgiver kan tilføje flere køretøjer
7. Tilføjede nummerplader gemmes på opslaget (persisteres i `metis_lookups.metadata`)

### PDF-rapport
9. Køretøjer inkluderes automatisk i Metis' PDF-rapport som ny sektion

## Arkitektur

### Registry-API (backend)

**Nye komponenter:**

```
SynsbasenClient (NY)
├── getByRegistration(string $registration, bool $includeTinglysning = true): ?array
├── getByVin(string $vin, bool $includeTinglysning = true): ?array
└── isAvailable(): bool

VehicleService (NY)
├── findByRegistration(string $registration): ?Vehicle
└── findByVin(string $vin): ?Vehicle
```

**Nyt endpoint:**
- `POST /v1/vehicles/by-registration` — nummerplade → teknisk data + Bilbog-pant (via Synsbasen)

### Metis-package (frontend)

**Ny Livewire-komponent:**
- `VehicleSection` — automatisk load ved person/CVR-opslag
- Følger eksisterende mønster (MortgageSection, CompanyInfoSection)
- Køretøjskort med: mærke/model/årgang | km | syn | værdi | pant-status
- "Tilføj køretøj"-knap med nummerplade-input
- Nettoværdi per køretøj (estimeret værdi - pant)

**PDF-sektion:**
- Ny sektion "Køretøjer" efter ejendomssektionen
- Tabel per køretøj: teknisk data + pant/gæld
- Samlet køretøjsformue nederst

## Datamodel

### Vehicle response-objekt

```json
{
  "source": "synsbasen",
  "vin": "WBAPH5C55BA123456",
  "registration": "AB12345",
  "make": "BMW",
  "model": "520d",
  "variant": "Touring",
  "year": 2022,
  "fuel_type": "Diesel",
  "mileage": 87000,
  "last_inspection": "2025-11-15",
  "inspection_result": "Godkendt",
  "estimated_value": 285000,
  "liens": [
    {
      "type": "pantebrev",
      "creditor": "Nordea Finans",
      "amount": 195000,
      "currency": "DKK"
    }
  ],
  "net_value": 90000
}
```

### Database (Registry-API)

**`vehicles` tabel (Synsbasen-cache, samlet teknisk + Bilbog-data):**
- `id`, `vin` (unique), `registration` (unique), `make`, `model`, `variant`, `year`
- `fuel_type`, `mileage`, `last_inspection`, `inspection_result`
- `estimated_value` (nullable)
- `liens` (JSON — array af pant/udlæg fra Synsbasens tinglysning_data)
- `raw_data` (JSON — komplet Synsbasen-response inkl. tinglysning)
- `fetched_at`, `created_at`, `updated_at`

**Staleness:** 24 timer. Ved opslag: check `fetched_at` → hvis > 24t, hent frisk → gem → returnér.

## Caching-strategi

- Alt caches i én `vehicles` tabel med `registration` som primær lookup-key
- 24 timers staleness (matcher ejendomsdata)
- Cache invalideres ved nyt opslag efter 24t

## Env vars

**Registry-API (.env):**
- `SYNSBASEN_API_KEY` — ny, påkrævet
- `SYNSBASEN_BASE_URL=https://api.synsbasen.dk/v1` — ny, default

## Scope-afgrænsning

### Med i MVP
- Rådgiver-input af nummerplader ved CPR/CVR-opslag
- Synsbasen-berigelse (teknisk data + Bilbog-pant i ét kald)
- Køretøjskort i Metis UI
- PDF-rapport med køretøjssektion
- Synsbasen-caching (24t)
- Persistering af tilføjede nummerplader på opslaget

### Ikke med (afventer parallelle spor)
- Automatisk CPR/CVR → køretøjer (kræver DMR §17 eller direkte Bilbog-API)
- Leasing-detection (kræver DMR — leasingtager ≠ Bilbog-debitor)
- Køretøjs-historik (kun aktuel status)

## Parallelle spor

### DMR §17-ansøgning (sendt)
Ansøgning til Motorstyrelsen om terminaladgang under §17 stk. 2 nr. 7 (finansieringsvirksomhed via faktorkredit).
Fil: `Dropbox/Frankston/DATA PROVIDERS/Motorstyrelsen/ansogning-dmr-terminaladgang.md`
Hvis godkendt → automatisk reverse lookup CPR/CVR → alle ejede køretøjer.

### Tinglysningsretten (mail sendt)
Forespørgsel om SSL-API til Bilbogen eller dataleveringsaftale.
Fil: `Dropbox/Frankston/DATA PROVIDERS/Tinglysningsretten/mail-bilbog-api-adgang.md`
Hvis opnået → direkte Bilbog-adgang, sparer Synsbasen-tokens.

## Forudsætninger (verify før implementering)

1. **[VIGTIGT] Opret Synsbasen-konto** — tilmeld Basic (250 kr/md) og bekræft API-adgang
2. **[VIGTIGT] Synsbasen `estimated_value`** — bekræft at API'en returnerer markedsværdi. Hvis ikke, gør feltet nullable og vis "Værdi ukendt"
3. **[VIGTIGT] Synsbasen `tinglysning_data` format** — test ét opslag og dokumentér response-felter for pant/udlæg

## Design-beslutninger (fra spec review)

### Manuel persistering
Manuelt tilføjede nummerplader gemmes i `metis_lookups.metadata` (JSON-kolonne) på det aktuelle opslag. Ved PDF-generering og genbesøg hentes de derfra og beriges på ny.

### Samlet køretøjsformue
Vises som tre tal: **Samlet værdi** (brutto), **Samlet gæld** (sum af pant), **Netto** (værdi - gæld). Hvis `estimated_value` er null for et køretøj, ekskluderes det fra brutto med note "Værdi ukendt for X køretøjer".

### Duplikathåndtering
Dedupliker på VIN. Hvis et Bilbog-fundet køretøj også tilføjes manuelt, merges data — ikke dobbelt-optælling.

### Fejlhåndtering
- **Synsbasen nede:** Vis "Køretøjsdata midlertidigt utilgængelig" — resten af opslaget fungerer
- **Ugyldig nummerplade:** Vis "Køretøj ikke fundet — tjek nummerpladen"
- **Synsbasen kvote opbrugt:** Graceful degradation, vis fejlbesked
- **Tinglysning-data utilgængelig:** Vis teknisk data uden pant-info, med note "Pantdata utilgængelig"

### Timeout
Synsbasen-kald har 10s timeout. Hvert køretøj tilføjes individuelt af rådgiveren, så parallelisering er ikke nødvendig i MVP.

### Feature flag
`vehicle_enrichment` — feature flag til at togge sektionen per klient/site.

### Livewire-placering
VehicleSection vises kun for `cpr` og `cvr` opslag — ikke `person` (navnesøgning) eller `address`.

## Acceptance Criteria

1. [ ] Rådgiver kan tilføje køretøj via nummerplade ved CPR/CVR-opslag
2. [ ] Køretøj beriges med mærke, model, årgang, km, syn fra Synsbasen
3. [ ] Pant/gæld vises via Synsbasens tinglysning_data (kreditor, beløb, type)
4. [ ] Estimeret markedsværdi vises per køretøj (nullable — "Værdi ukendt" hvis ikke tilgængelig)
5. [ ] Nettoværdi (værdi - pant) beregnes og vises per køretøj
6. [ ] Samlet køretøjsformue: brutto, gæld og netto vises
7. [ ] Tilføjede nummerplader persisteres på opslaget (overlever page reload)
8. [ ] Duplikater (samme VIN/nummerplade) forhindres
9. [ ] Køretøjer vises i PDF-rapport med teknisk data + pant/gæld
10. [ ] Samlet køretøjsformue (brutto/gæld/netto) vises i PDF
11. [ ] Data caches i 24 timer — gentagne opslag bruger cache
12. [ ] Graceful degradation: Synsbasen nede → fejlbesked, resten af opslaget fungerer
13. [ ] Feature flag `vehicle_enrichment` toggler sektionen
14. [ ] VehicleSection vises kun for `cpr` og `cvr` opslag
15. [ ] Tests: SynsbasenClient, VehicleService, API-endpoint, VehicleSection Livewire
