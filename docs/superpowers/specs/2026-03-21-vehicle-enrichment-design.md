# Metis Vehicle Enrichment — Design Spec

**Date:** 2026-03-21
**Status:** Approved
**Author:** Frederik Nielsen + Claude

## Summary

Udvid Metis med automatisk køretøjsberigelse ved person- og virksomhedsopslag. Systemet henter automatisk pant/gæld på køretøjer fra Bilbogen via CPR/CVR og beriger med tekniske data fra Synsbasen API. Rådgivere kan desuden manuelt tilføje nummerplader for gældsfrie biler.

## Motivation

Investeringsrådgivere og långivere har behov for et samlet formuebillede inkl. køretøjer. Metis beriger i dag med ejendomme, virksomheder og persondata — køretøjer er et naturligt næste skridt. Særligt relevant for kreditvurdering, hvor køretøjsgæld og -aktiver er væsentlige.

## Datakilder

### Bilbogen (tinglysning.dk) — Automatisk
- **Adgang:** Eksisterende SSL-certifikat (MitID Erhverv), allerede deployed i Registry-API
- **Søgning:** CPR eller CVR → alle køretøjer med registrerede hæftelser
- **Data:** Pant (pantebrev, ejendomsforbehold, udlæg), kreditor, beløb, stelnummer
- **Pris:** Gratis
- **Begrænsning:** Kun køretøjer med registreret gæld. Gældsfrie biler og privatleasing dukker ikke op

### Synsbasen API — Berigelse
- **Adgang:** REST API med API-nøgle, ny integration
- **Søgning:** Stelnummer (fra Bilbogen) eller registreringsnummer (manuelt input)
- **Data:** Mærke, model, variant, årgang, brændstof, km-stand, synshistorik, estimeret værdi, afgifter
- **Pris:** ~250 kr/md for 2.500 opslag
- **Begrænsning:** Ingen ejerdata (kun teknisk)

### Manuel input — Supplement
- Rådgiver indtaster nummerplader for biler uden gæld
- Beriges via Synsbasen + Bilbogen (bekræft ingen pant)

## Brugerflow

### Automatisk (ved ethvert CPR/CVR-opslag i Metis)
1. Rådgiver slår person (CPR) eller virksomhed (CVR) op
2. Metis kalder Registry-API endpoint `/v1/vehicles/by-cpr` eller `/v1/vehicles/by-cvr`
3. Registry-API kalder Bilbogen SSL-API med CPR/CVR → returnerer køretøjer med pant
4. For hvert stelnummer fra Bilbogen → Synsbasen API beriger med teknisk data
5. Samlet response vises som ny "Køretøjer"-sektion i Metis

### Manuel (supplement)
6. Rådgiver klikker "Tilføj køretøj" → indtaster nummerplade
7. Registry-API kalder Synsbasen (nummerplade → teknisk data) + Bilbogen (stelnummer → evt. pant)
8. Køretøjet tilføjes listen og gemmes på opslaget

### PDF-rapport
9. Køretøjer inkluderes automatisk i Metis' PDF-rapport som ny sektion

## Arkitektur

### Registry-API (backend)

**Nye/udvidede komponenter:**

```
TinglysningClient (eksisterende, udvides)
├── searchBilbogByCpr(string $cpr): array     // NYT
├── searchBilbogByCvr(string $cvr): array     // NYT
├── searchBilbogByVin(string $vin): array     // NYT
└── (eksisterende ejendoms-metoder)

SynsbasenClient (NY)
├── getByVin(string $vin): ?array
├── getByRegistration(string $registration): ?array
└── isAvailable(): bool

VehicleService (NY)
├── findByCpr(string $cpr): VehicleCollection
├── findByCvr(string $cvr): VehicleCollection
├── findByRegistration(string $reg): ?Vehicle
└── enrichWithSynsbasen(array $bilbogData): array
```

**Nye endpoints:**
- `POST /v1/vehicles/by-cpr` — automatisk Bilbog + Synsbasen
- `POST /v1/vehicles/by-cvr` — automatisk Bilbog + Synsbasen
- `POST /v1/vehicles/by-registration` — manuel nummerplade-opslag

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
  "source": "bilbogen",
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

**`vehicles` tabel (Synsbasen-cache):**
- `id`, `vin` (unique), `registration`, `make`, `model`, `variant`, `year`
- `fuel_type`, `mileage`, `last_inspection`, `inspection_result`
- `estimated_value`, `raw_synsbasen_data` (JSON)
- `fetched_at`, `created_at`, `updated_at`

**`vehicle_liens` tabel (Bilbog-cache):**
- `id`, `vin`, `search_type` (cpr/cvr), `search_value`
- `lien_type` (pantebrev/ejendomsforbehold/udlaeg)
- `creditor`, `amount`, `currency`
- `raw_bilbog_data` (JSON)
- `fetched_at`, `created_at`, `updated_at`

**Staleness:** 24 timer. Ved opslag: check `fetched_at` → hvis > 24t, hent frisk → gem → returnér.

## Caching-strategi

- Bilbog-data caches i `vehicle_liens` med `search_type` + `search_value` som lookup-key
- Synsbasen-data caches i `vehicles` med `vin` som lookup-key
- 24 timers staleness (matcher ejendomsdata)
- Cache invalideres ved nyt opslag efter 24t
- Manuel nummerplade-opslag caches også

## Env vars

**Registry-API (.env):**
- `SYNSBASEN_API_KEY` — ny, påkrævet
- `SYNSBASEN_BASE_URL=https://api.synsbasen.dk/v1` — ny, default
- Tinglysnings-certifikat: allerede konfigureret (`storage/certificates/Tinglysningen.pem/.key`)

## Scope-afgrænsning

### Med i MVP
- Automatisk Bilbog-opslag ved CPR/CVR-søgning
- Synsbasen-berigelse per fundet køretøj
- Manuel tilføjelse af nummerplader
- Køretøjskort i Metis UI
- PDF-rapport med køretøjssektion
- Bilbogen/Synsbasen-caching (24t)

### Ikke med (afventer §17-ansøgning)
- DMR ejerdata (reverse lookup CPR/CVR → alle ejede køretøjer)
- Leasing-detection (kræver DMR — leasingtager ≠ Bilbog-debitor)
- Køretøjs-historik (kun aktuel status)

## Parallelt spor: DMR §17-ansøgning

Ansøgning til Motorstyrelsen om terminaladgang under §17 stk. 2 nr. 7 (finansieringsvirksomhed via faktorkredit) er udarbejdet og klar til afsendelse.

Fil: `Dropbox/Frankston/DATA PROVIDERS/Motorstyrelsen/ansogning-dmr-terminaladgang.md`

Hvis godkendt (forventet 10-20 uger):
- Tilføj `DmrService` til Registry-API
- Samtykkebaseret flow (§17 nr. 7 kræver ejerens samtykke)
- Automatisk discovery af ALLE ejede køretøjer (inkl. gældsfrie + leasede)

## Acceptance Criteria

1. [ ] CPR-opslag i Metis viser automatisk køretøjer med pant/gæld fra Bilbogen
2. [ ] CVR-opslag i Metis viser automatisk køretøjer med pant/gæld fra Bilbogen
3. [ ] Hvert køretøj beriges med mærke, model, årgang, km fra Synsbasen
4. [ ] Estimeret markedsværdi vises per køretøj
5. [ ] Nettoværdi (værdi - pant) beregnes og vises
6. [ ] Rådgiver kan manuelt tilføje køretøj via nummerplade
7. [ ] Manuelt tilføjede køretøjer beriges med Synsbasen + Bilbog-data
8. [ ] Køretøjer vises i PDF-rapport med teknisk data + pant/gæld
9. [ ] Samlet køretøjsformue vises i PDF
10. [ ] Data caches i 24 timer — gentagne opslag inden for 24t bruger cache
11. [ ] Fejlhåndtering: Bilbogen/Synsbasen utilgængelig → sektion vises med fejlbesked, resten af opslaget fungerer
12. [ ] Tests: Bilbog-integration, Synsbasen-integration, VehicleService, API-endpoints
