# Persongraf på navne-siden (selskabslag uden CPR)

**Dato:** 2026-07-28 · **Status:** afventer Frederiks review · **Kontekst:** Frederiks test 28/7 — navne-søgning på en person med 7 ejendomsselskaber gav ingen graf; grafen bor i dag KUN på CPR-siden. Med kun et navn skal man kunne se selskabs-grafen (ejerskab + roller + datterselskaber + ejendomme via selskaber); privat-laget forbliver CPR-eksklusivt.

## Mål

1. Navne-siden (`/lookup/person/{navn}`) får samme Selskabsstruktur-graf som CPR-siden — chips, progressive faser, hover-kort, det hele — men UDEN "Private ejendomme"-chippen.
2. En diskret note i graf-sektionen forklarer at CPR-opslag også giver private ejendomme.
3. CPR-siden er 100 % uændret.

## Datagrundlag (verificeret i kode 28/7)

| Sti | Kilde | Companies-shape |
|---|---|---|
| CPR (`/v1/cvr/search-by-cpr`) | lokal DB (`formatPersonCompanies`) | `cvr, name, company_type, status, is_active, has_direct_ownership, roles[{role, title, ownership_share, is_current, start_date, end_date}], financials` — det `PersonStructure::classify()` kræver |
| Navn (`/v1/cvr/person-roles`) | LIVE CVR-ES deltager-doc (`searchPersonRolesByName` → `searchDeltager(name, null)`, første match) | `cvr, name, company_type, status, roles[{role_label, role_type, is_current, start_date}]` — **ingen `is_active`, ingen `ownership_share`** |

Deltager-dokumentet BÆRER ejerandelene: `virksomhedSummariskRelation → organisationer → medlemsData → attributter → EJERANDEL_PROCENT` (udtræksmønsteret findes allerede i `fetchForeignOwnerLeaves`, CvrService ~:3026). Navne-stien kan derfor udvides til CPR-kompatibel shape uden DB-afhængighed.

## Arkitektur

### 1. registry-api: nyt endpoint `POST /v1/cvr/person-companies-by-name`

Eksisterende `person-roles`-endpoint røres IKKE (navne-sidens roller-sektion beholder sin payload). Nyt endpoint emitterer en **search-by-cpr-KOMPATIBEL** companies-liste bygget live fra deltager-dokumentet:

- `is_active`: mindst én rolle-periode med `gyldigTil === null` i virksomheden (samme semantik som CPR-stiens is_current-baserede regel; virksomhedsstatus indgår ikke — heller ikke i CPR-stien)
- `has_direct_ownership`: KUN EJERREGISTER-roller med andel tæller (CPR-stiens regel 1:1: LegalOwner/Shareholder — **"Reelle ejere" er INDIREKTE og tæller IKKE**; at bryde den regel ville tegne falske ejerskabskanter)
- `ownership_share`: seneste `EJERANDEL_PROCENT`-periode (`gyldigTil === null`), decimal 0-100
- `roles`: mappet til CPR-stiens feltnavne (`role` via eksisterende `mapRoleType`, `title` = rå label, `is_current`, `start_date`)
- `person_name` pr. række (PersonStructure::personLabel() læser den derfra)
- **`financials` udelades bevidst** — skelettet bruger dem ikke (enrichment-fasen henter selv nøgletal), og feltet er det dyreste i CPR-payloaden
- Cache 1h pr. navn (sha1-nøgle). Eksplicit `timeout()` + `connectTimeout()` (cURL-28-lektionen, jf. #194). 404 når `searchDeltager` intet finder — IKKE tom liste (null ≠ tom)

### 2. metis-package: `RegistryApi::fetchCompaniesByName(string $name): ?array`

Standard `post()` — arver transport-hærdningen (60s/retry/`upstream_error`-shape) automatisk.

### 3. metis-package: `PersonStructure` får `public string $source = 'cpr'`

Eksplicit prop fra blade — ALDRIG afledt af query-formen (et navn på 10 tegn må ikke kunne fejlklassificeres):

- `fetchCompanies()` vælger endpoint efter `$source`
- **Name-mode ved mount:** `$layers = ['ownership', 'roles']` (private_properties er ALDRIG i layers — chippen findes ikke, ikke "tom"), `privatePropertiesStatus = 'empty'` (settled fra start ⇒ #128's provisoriske empty-gren viser beskeden DIREKTE uden shimmer/poll-vent; `tick()`s empty-gren no-op'er fordi status ikke er 'pending'; poll-gaten venter ikke på fasen)
- ALT andet genbruges uændret: strukturer/ejendomme/enrichment-faserne, chips-mekanikken, aldrig-tom-reglen, rehydrering, retry-kaskaden — de er cvr-nøglede og ligeglade med hvor skelettet kom fra

### 4. UI

- `lookup.blade.php` person-gren: `<livewire:metis-person-structure :query="$query" source="name" lazy />` — placeret full-bleed som på CPR-siden, over `metis-person-roles`
- Note i graf-sektionen, kun i name-mode, både ved graf og ved tom-tilstand (særligt vigtig dér — personen KAN have private ejendomme vi ikke kan se): `mgraph-note`-stil, "Søg med CPR-nummer for også at se personens private ejendomme." Ingen farvede callout-bokse.

## Navnebror-ambiguitet — BESLUTTET (kan vetoes i review)

v1 bruger **samme deltager som resten af navne-siden** (`searchDeltager` første match). Grafen ændrer ikke identitetsrisikoen — den viser de samme roller grafisk, og en graf der peger på en anden person end roller-sektionen ville være værre end status quo. Den rigtige løsning på navnebrødre er `personDisambiguate`-endpointet (F5, enhedsnummer-valg) — det er en egen runde med picker-UI og enhedsnummer-nøglede opslag, og den løfter HELE navne-siden, ikke kun grafen. Non-goal her.

## Fejl og kanter

- Fetch fejler → `skeletonStatus = 'failed'` + retry (uændret mønster; null ≠ tom)
- 0 aktive selskaber → "Ingen aktive selskabsrelationer" med det samme (ingen provisorisk fase i name-mode) + CPR-noten
- Enkeltmandsvirksomheder/udenlandske deltagere uden EJERANDEL_PROCENT → `ownership_share = null`, kanten tegnes uden procent-label (builderen håndterer allerede null-share)

## Tests

**registry-api** (deltager-fixture): andel-udtræk seneste periode; `is_active` false når alle perioder lukkede; `has_direct_ownership` false for ren "Reelle ejere"-relation (regel-pin); 404 ved ukendt navn.

**metis-package:**
- Name-mode mount: `private_properties` ikke i layers, ingen chip i DOM, `privatePropertiesStatus` 'empty' fra mount, **person-portfolio-endpointet kaldes ALDRIG** — håndhævet via `Http::fake` UDEN person-portfolio-mønster + `Http::preventStrayRequests()` (🚨 IKKE `Http::assertNotSent` — kendt inert sammen med pool, dokumenteret klasse)
- Skelet fra name-endpoint → graf med ejerskabskant inkl. procent-label
- 0 aktive → besked + CPR-note direkte, ingen poll
- Failed → retry-affordance
- Regression: `source`-default 'cpr' ⇒ eksisterende CPR-tests uændrede grønne
- Mutations-tjek på de nye tests (fejler uden implementering)

## Non-goals

Disambiguation-picker (F5-runden) · private ejendomme via navn · enhedsnummer-baserede URL'er · ændringer af PersonRoles/person-roles-endpointet · finansdata i navne-payloaden.

## Estimat

registry-api ~1 dag (endpoint + extraction + tests, manuel deploy) · metis ~1 dag (mode + blade + tests) · sekvens: registry-api FØRST (metis-testene faker endpointet, men prod-verifikation kræver det live).
