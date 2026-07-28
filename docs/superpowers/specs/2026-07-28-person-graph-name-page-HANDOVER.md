# HANDOVER — Persongraf på navne-siden (selskabslag uden CPR)

**Fra:** Metis-session 28/7-2026 (chips A+B + #128 leveret samme dag)
**Til:** agent i anden terminal, der skal eksekvere opgaven
**Autoritativt grundlag:** `metis-package/docs/superpowers/specs/2026-07-28-person-graph-name-page-design.md` (commit `7bb542c` på main — læs den FØRST og følg den; Frederik har afleveret denne handover som accept af spec'en, men overflad væsentlige afvigelser i stedet for at improvisere)

## Opgaven i én sætning

Navne-siden (`/lookup/person/{navn}`) skal have samme Selskabsstruktur-graf som CPR-siden (chips, progressive faser, hover-kort) — men UDEN "Private ejendomme"-chippen — plus en diskret note om at CPR-opslag også giver private ejendomme. CPR-siden er 100 % uændret.

## Arbejdsform (bindende)

- Spec'en ER skrevet (brainstorm-fasen er afsluttet). Din vej: `writing-plans` → SDD-eksekvering (subagent-driven) → verifikations-tjek → PR-par.
- Læs `memory/company_overview.md` + BUNDEN af `memory/project_metis_ownership_graph_relations.md` (de seneste sessioners fælder og status — særligt chips- og #128-afsnittene).
- Verifikations-tjek før enhver completion-claim (kommandoer + observeret output). Mutations-tjek på nye tests — dette repo-kompleks har lavet "grøn-men-inert-test"-fejlen 3+ gange.
- Prod-verifikation kræver browser m. åben konsol. Metis' lazy-sektioner fyrer først intersects ved SCROLL i automatiserede browser-sessioner.

## Repo-kort og deploy-fakta

| Repo | Lokal sti | Deploy |
|---|---|---|
| registry-api | `~/Herd/registry-api` | **IKKE auto-deploy** — manuel Forge-trigger (server 1167658, site 3058307). POST = prod-mutation |
| metis-package | `~/code/metis-package` | pakke; deployes via host-bump |
| metis (host) | `~/Herd/metis` | auto-deploy v. merge til main (site 3093070). Efter blade-ændringer: `php artisan view:clear` via Forge command-API |

- Forge-token: `~/.config/forge/config.json` (key `token`). Command-API-output er TOP-LEVEL `d['output']`. Forge-API flakiness: `curl --http1.1 --retry 4 --retry-all-errors`.
- Bump-flow: merge package-PR → `composer update thefountainhead/metis` i host → bump-PR → merge = deploy.
- **Sekvens: registry-api FØRST** (metis-testene faker endpointet, men prod-verifikation kræver det live).

## 🚨 Koordinering med andre agenter

- En ANDEN agent kan være i gang i registry-api med plandata/lokalplan-endpoints (advokat-spor 2-handoveren). I tilføjer begge endpoints — merge-konflikter er usandsynlige, men registry-api-deploys er manuelle og fælles: **tjek `git log origin/main` og åbne PR'er før du deployer**, og deploy aldrig en anden agents u-mergede arbejde.
- registry-api har en FREMMED stash ("gate 1 investigation") — rør den ikke.

## Nøgle-interfaces (verificeret i kode 28/7 — genopdag dem ikke)

- **Målshape** = search-by-cpr's companies-rækker (`CvrService::formatPersonCompanies`, ~:1981): `cvr, name, company_type, status, is_active, has_direct_ownership, roles[{role, title, ownership_share, is_current, start_date, end_date}]` + `person_name` pr. række. `financials` udelades BEVIDST i det nye endpoint.
- **Kilde:** CVR-ES deltager-doc via `searchDeltager(name, null)` (første match — samme som navne-sidens eksisterende `searchPersonRolesByName`, CvrService ~:1096). Ejerandele: `virksomhedSummariskRelation → organisationer → medlemsData → attributter → EJERANDEL_PROCENT` — udtræksmønster findes i `fetchForeignOwnerLeaves` (~:2973-3030).
- **Ejerskabsregel 1:1 fra CPR-stien:** kun EJERREGISTER (LegalOwner/Shareholder) m. andel = `has_direct_ownership`. **"Reelle ejere" er INDIREKTE og tæller IKKE** — at bryde den regel tegner falske ejerskabskanter.
- **metis-forbruger:** `PersonStructure::classify()` (src/Livewire/Sections/PersonStructure.php ~:378) filtrerer på `is_active` + `cvr` og splitter på `has_direct_ownership`. `personLabel()` læser `person_name` fra rækkerne.
- **Name-mode i PersonStructure:** eksplicit `public string $source = 'cpr'`-prop fra blade (ALDRIG afledt af query-formen). Ved mount i name-mode: `$layers = ['ownership','roles']` (private_properties ALDRIG i layers) + `privatePropertiesStatus = 'empty'` — så opfører #128's provisorisk-empty-mekanik sig korrekt uden specialtilfælde (tick's empty-gren no-op'er på ikke-'pending'; poll-gaten venter ikke; empty-beskeden vises direkte).

## Kendte miner (dyrekøbte — spring dem ikke)

- 🚨 **#128-semantikken:** 'empty'-skelet er PROVISORISK indtil privat-fasen har svaret (kun CPR-mode). Din name-mode skal sætte fasen settled fra mount — gør du det IKKE, viser navne-siden shimmer+poll for evigt. Læs #128's commit-besked og tests i `PersonStructureTest.php` ("promotes an empty skeleton…").
- 🚨 **Http::fake-rækkefølge:** person-mønstre SKAL registreres FØR den generiske `*/property-portfolio*`-wildcard (insertion-order-match; wildcarden matcher person-URL'en og afgør ellers testens udfald). Dokumenteret i `fakePersonPrivate` i PersonStructureTest.
- 🚨 **`Http::assertNotSent` + `Http::pool` er INERT** — håndhæv "endpoint kaldes aldrig" via `Http::fake` UDEN mønstret + `Http::preventStrayRequests()`.
- Fang ALDRIG `\Throwable` rundt om HTTP i registry-api-tests (sluger StrayRequestException). Ingen globale `function`-helpers i Pest-filer der kolliderer på navn.
- Eksterne HTTP-kald i registry-api: eksplicit `timeout()` + `connectTimeout()` ALTID (cURL-28/budget-inversion, jf. #194). Statiske log-BESKEDER (varierende detaljer i context, aldrig i message).
- `retry($times)` i Laravel HTTP = TOTALE forsøg (`retry(1)` er no-op); med retry aktiv kaster fejl-statusser uden `->throw()` — brug `throw: false` hvis no-throw-semantik skal bevares. metis' `RegistryApi::post()` har allerede transport-hærdningen — nye metoder arver den ved at bruge `post()`/`get()`.
- null ≠ tom: fejlet kald må ALDRIG rendere som "ingen selskaber" — 404 fra endpointet når deltager ikke findes, `skeletonStatus='failed'` + retry i komponenten.
- CPR må ALDRIG optræde i cache-keys (sha1), URL'er, node-ids, log-beskeder eller Flare-payloads — gælder også selvom denne opgave er navne-baseret (delte kodestier).
- Python-patch-scripts: ALTID no-op-guard (`assert patched != s`).
- 🖥️ ÉN `--parallel`-testkørsel ad gangen på 8GB-Mac'en.

## Verifikationsmål (prod, m. konsol)

Frederiks egen test-case fra 28/7: navne-søgning på **"frederik larnæs"** (person m. roller/ejerskab i bl.a. Trygve Ejendomme A/S, FDL-Invest ApS, 7 selskaber m. 35 ejendomme). Forvent: graf m. ejerskabskanter (procent-labels hvor EJERREGISTER har andel), chips "Ejerskab/Roller" m. badges, datterselskabs-/ejendomsfaser der loader progressivt, CPR-noten synlig, INGEN "Private ejendomme"-chip. Regression: CPR-siden (admin-CPR) uændret inkl. privat-ejendoms-laget.

## Ikke din opgave

Disambiguation-picker (F5/`personDisambiguate` — egen runde) · private ejendomme via navn · ændringer af `person-roles`-endpointet eller PersonRoles-sektionen · advokat-sporene (anden agents handover) · registry-api #193/#195.
