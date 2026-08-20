<?php

namespace TheFountainhead\Metis\Services;

use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

class RegistryApi
{
    /**
     * Bounds fetchCompanyInfosPooled()'s Http::pool() fan-out. Without an
     * explicit value, this Laravel version treats null concurrency as
     * UNLIMITED — a graph with ~120 stub-companies would fire every request
     * at once against registry-api instead of trickling through a queue.
     */
    protected const POOL_CONCURRENCY = 6;

    /**
     * Bounds fetchCompanyStructuresPooled()'s Http::pool() fan-out. Lower
     * than POOL_CONCURRENCY: company-structure is a heavier endpoint
     * (koncerntræ-walk) than the plain company-info lookup, so this trickles
     * more conservatively against registry-api.
     */
    protected const STRUCTURE_POOL_CONCURRENCY = 3;

    /**
     * Sat naar en 503 har overlevet begge retries i client()'s when-callback:
     * saa er registry-api i et laengere maintenance-vindue (minutter, ikke
     * sekunder), og yderligere retries koeber kun ventetid. Efterfoelgende
     * 503'er paa SAMME instans fejler straks — vigtigt for de stier der laver
     * mange kald i traek (searchPersonByName's per-person property-counts),
     * som ellers ville betale op til 7s sleep PR. DELKALD gennem hele vinduet.
     *
     * Instans-state, ikke cache: hver Livewire-lazy-section er sit eget
     * HTTP-request med sin egen service-instans, saa flaget doer med
     * requestet. Under FPM er det praecis den oenskede levetid; koerer appen
     * en dag under Octane, maa flaget IKKE flyttes til en singleton uden
     * reset-mekanisme.
     */
    protected bool $maintenanceObserved = false;

    /**
     * Er kvoten opbrugt for den aktuelle session?
     *
     * 🚨 REVIEW-FUND 9/8, VERIFICERET MED EN KOERENDE EXPLOIT. Kvote-gaten sad
     * foerst kun i `Lookup::mount()` og skjulte sektionerne i bladen. Men hver
     * sektion er en SELVSTAENDIG `lazy` Livewire-komponent med sin egen
     * adresse — den kan mountes direkte over `/livewire/update` uden
     * nogensinde at roere `Lookup`:
     *
     *     session(['metis_lookup_count' => 999]);          // kvote opbrugt
     *     Livewire::test('metis-company-info', ['query' => '37792594']);
     *     -> {"name":"HEMMELIG A/S","cvr":"37792594", ...}
     *
     * Endnu vaerre i praksis: ét gratis opslag giver browseren gyldige,
     * korrekt-checksummede `__lazyLoad`-payloads. Replay af dem EFTER kvoten
     * er brugt op svarer 200 med data — ingen cookie-rydning, ingen
     * forfalskning, for checksummen kom fra vores eget svar.
     *
     * 🔑 DERFOR HER OG IKKE I KOMPONENTERNE. To forsoeg i `MetisSection`
     * fejlede maalt: `booted()` koerer EFTER `mount()`, og et flag stopper
     * ingen hentning. Alle 28 sektioner gaar gennem `client()` — det er dér
     * alle veje moedes. En guard pr. `mount()` ville vaere samme fejl ét
     * niveau nede: den 29. sektion ville mangle den.
     *
     * 🪤 BAGGRUNDSJOB RAMMES IKKE. De koerer uden session, saa
     * `metis_lookup_count` er fravaerende og gaten inaktiv. Ingen undtagelse
     * noedvendig — og dermed ingen undtagelse der kan blive den nye bypass.
     */
    protected function kvoteOpbrugt(): bool
    {
        if (! config('metis.gating.enabled', true)) {
            return false;
        }

        if (! app()->bound('session') || ! session()->isStarted()) {
            return false;
        }

        if (session('metis_user_token') || session('metis_verified_email')) {
            return false;
        }

        return session('metis_lookup_count', 0) > config('metis.gating.free_lookups', 1);
    }

    protected function client()
    {
        // 🚨 KVOTE-GATEN SIDDER HER, ikke i `get()`. Maalt 9/8: FIRE kaldsteder
        // gaar uden om `get()`/`getEnvelope()` — to `Http::pool()`-fan-outs og
        // to direkte `$this->client()`-kald. En gate i `get()` alene ville
        // altsaa lade netop de tunge, pooled opslag slippe igennem.
        //
        // `client()` er det ENESTE punkt alle kald deler, ogsaa fremtidige.
        // Se `kvoteOpbrugt()` for den maalte exploit.
        if ($this->kvoteOpbrugt()) {
            throw new QuotaExceededException;
        }

        // F1 pilot — if user has set personal token in session (via /alerts
        // token-input form), use it. Otherwise fall back to shared tenant key.
        // Session token enables per-user data (watchlists, alerts) on a
        // standalone Metis without full sign-up/login plumbing.
        $token = session('metis_user_token') ?: config('metis.registry_api.key');

        // Transport-hærdning (Flare 9097433, 27/7-26): registry-api's kolde
        // search-by-cpr-sti har et server-worst-case på ~40s (to kædede
        // gov-API'er synkront) — 30s klient-timeout timede ud BY CONSTRUCTION.
        // Mønstret her er propageret fra m2softs RegistryApiService (bevist
        // mod SAMME backend): 60s budget, 10s connect, én retry KUN på
        // transport-fejl (ConnectionException) — aldrig på modtaget 4xx/5xx,
        // som er svar, ikke transportstøj (eneste undtagelse: 503, se næste
        // blok). NB retry($times) er TOTALE forsøg,
        // ikke antal retries — retry(1) er en no-op (empirisk verificeret;
        // m2softs retry(1, ...) har samme fælde). retry(throw: true) er
        // bevidst: throw:false gør IKKE kaldet kaste-frit for
        // ConnectionException (ingen Response at returnere) — get()/post()
        // fanger i stedet. throw: false er PÅKRÆVET af to grunde: (1) uden
        // den ville retry-laget kaste RequestException på ENHVER fejl-status
        // selv i kald uden ->throw() (fx searchPersonByName's 500→0-fallback,
        // som ville knække); (2) den gør IKKE ConnectionException kaste-fri
        // (ingen Response at returnere ved exhaustion) — hvilket her er
        // præcis den ønskede asymmetri: status-fejl opfører sig som før,
        // transport-fejl når stadig get()/post()'s catch.
        // 503-undtagelsen fra "aldrig retry paa modtaget status" (7/8-26):
        // registry-api quick-deployer ved merge, og deploy-scriptet svarer 503
        // (maintenance) mens migrate koerer — typisk sekunder. Den 503 er ikke
        // et svar paa SPOERGSMAALET, det er "proev igen om lidt" — 56 Flare-
        // rescue-rapporter paa 9 dage, hver = en sektion der loej "ingen data"
        // for brugeren. To retries (2s+5s) rider et normalt deploy af;
        // overlever 503'en begge, er det et langt vindue (fx indeks-bygning
        // 7/8: ~4 min) — saa saetter vi maintenanceObserved og alle videre
        // kald paa instansen fejler straks til de eksisterende fallbacks,
        // praecis som foer denne aendring. Transport-retryen er UAENDRET
        // (1 retry, 2s): tael-logikken i callbacken holder de to fejlklasser
        // adskilt, selv om retry-arrayet nu tillader op til 3 forsoeg.
        $transportRetries = 0;
        $maintenanceRetries = 0;

        return Http::withToken($token)
            ->acceptJson()
            ->timeout(60)
            ->connectTimeout(10)
            ->retry([2000, 5000], 0, function (\Exception $e) use (&$transportRetries, &$maintenanceRetries) {
                if ($e instanceof ConnectionException) {
                    return ++$transportRetries <= 1;
                }

                if ($e instanceof RequestException && $e->response->status() === 503) {
                    if ($this->maintenanceObserved) {
                        return false;
                    }

                    if (++$maintenanceRetries >= 2) {
                        $this->maintenanceObserved = true;
                    }

                    return $maintenanceRetries <= 2;
                }

                return false;
            }, throw: false)
            ->baseUrl(config('metis.registry_api.url'));
    }

    public function fetchCompany(string $cvr): array
    {
        $result = $this->get("/v1/cvr/company/{$cvr}");

        if (isset($result['error'])) {
            return $result;
        }

        $company = $result['company'] ?? null;

        if (! $company) {
            return [];
        }

        $persons = collect($company['roles'] ?? [])
            ->filter(fn ($r) => $r['is_current'] ?? true)
            ->map(fn ($role) => [
                'name' => $role['person_name'] ?? $role['parent_company_name'] ?? 'Ukendt',
                'role' => $role['role_label'] ?? $role['role'] ?? '',
            ])
            ->values()
            ->all();

        return [
            'company' => [
                'name' => $company['name'] ?? 'Ukendt',
                'cvr' => $company['cvr'] ?? $cvr,
                'status' => $company['status'] ?? '',
                'type' => $company['long_company_type'] ?? $company['company_type'] ?? '',
                'address' => trim(($company['address'] ?? '') . ', ' . ($company['postal_code'] ?? '') . ' ' . ($company['city'] ?? ''), ', '),
                'industry' => $company['industry'] ?? null,
                'founded' => $company['founded_date'] ?? null,
            ],
            'persons' => $persons,
        ];
    }

    public function searchByName(string $name): array
    {
        $result = $this->post('/v1/cvr/search-by-name', ['name' => $name]);

        // 🚨 GIV FEJLEN VIDERE. `$result['companies'] ?? []` destruerede den
        // uigenkaldeligt: en fejl har ingen 'companies'-noegle, saa kalderen
        // fik en tom liste og kunne ALDRIG se forskel paa "ingen selskaber"
        // og "opslaget fejlede". Search.php:576 gater paa isset($result['error'])
        // og saa derfor intet — brugeren fik "ingen resultater" for et opslag
        // der aldrig lykkedes. Samme falske benaegtelse som adressesiden,
        // bare paa selskabssoegningen. fetchProperty():317 gjorde det rigtigt.
        if (isset($result['error'])) {
            return $result;
        }

        return $result['companies'] ?? [];
    }

    public function searchPersonByName(string $name): array
    {
        $result = $this->fetchPersonRoles($name);

        if (! $result || ! isset($result['person_name'])) {
            return [];
        }

        // Transform to array of persons with roles for blade template
        $roles = collect($result['companies'] ?? [])
            ->filter(fn ($c) => ($c['status'] ?? '') === 'NORMAL' || ($c['status'] ?? '') === '')
            ->flatMap(fn ($company) => collect($company['roles'] ?? [])
                ->map(fn ($role) => [
                    'company' => $company['name'] ?? '',
                    'cvr' => $company['cvr'] ?? '',
                    'role' => $role['role_label'] ?? '',
                    'is_current' => $role['is_current'] ?? false,
                ]))
            ->unique(fn ($r) => $r['cvr'].$r['role'])
            ->values()
            ->all();

        // Find companies where person is owner (Reelle ejere / EJERREGISTER)
        $ownedCompanies = collect($result['companies'] ?? [])
            ->filter(fn ($c) => ($c['status'] ?? '') === 'NORMAL')
            ->filter(fn ($c) => collect($c['roles'] ?? [])
                ->contains(fn ($r) => in_array($r['role_label'] ?? '', ['Reelle ejere', 'EJERREGISTER']) && ($r['is_current'] ?? false)))
            ->take(10)
            ->map(function ($c) {
                $cvr = $c['cvr'] ?? '';
                $ownership = collect($c['roles'] ?? [])
                    ->where('is_current', true)
                    ->whereIn('role_label', ['Reelle ejere', 'EJERREGISTER'])
                    ->max('ownership_share');

                // Count properties via cached portfolio or quick API check.
                // Kaldet bruger ikke ->throw(), så en fejl-status giver bare
                // json()===null (fanges af ?? 0) — kun ConnectionException
                // kastes. Fang derfor præcis den og ikke \Throwable: en
                // catch-all ville også sluge testenes StrayRequestException,
                // så en omdøbning af endpointet stille ville rapportere 0
                // ejendomme i stedet for at fejle testen.
                try {
                    $propertyCount = $this->client()->timeout(5)
                        ->get("/v1/company/{$cvr}/property-portfolio", ['limit' => 0])
                        ->json('data.portfolio.total_count') ?? 0;
                } catch (ConnectionException $e) {
                    // rescue() rapporterede automatisk; bevar den observability.
                    report($e);

                    $propertyCount = 0;
                }

                return [
                    'name' => $c['name'] ?? '',
                    'cvr' => $cvr,
                    'ownership' => $ownership,
                    'property_count' => $propertyCount,
                ];
            })
            ->values()
            ->all();

        $totalProperties = collect($ownedCompanies)->sum('property_count');

        return [[
            'name' => $result['person_name'],
            'roles' => $roles,
            'owned_companies' => $ownedCompanies,
            'total_properties' => $totalProperties,
        ]];
    }

    /**
     * Stil et aggregeret spoergsmaal om en POPULATION af ejendomme.
     *
     * 🔑 Et ANDET produkt end opslaget: soegning finder én ting man kender
     * navnet paa; det her afgraenser en maengde og taeller. Frederiks idé 9/8.
     *
     * 🚨 SVARET BAERER SIN DAEKNING i `meta.daekning` — maalt paa prod 10/8:
     * kun ~70 % af pantebrevene i et typisk postnummer har en kendt rentesats,
     * og resten er `variabel`/`kontantlaan`, som HAR en rente vi ikke kender.
     * Et praecist tal uden det forbehold kan foere til en forkert
     * kreditbeslutning. UI'et SKAL vise `daekning`.
     *
     * @return array{data?: array<string,mixed>, meta?: array<string,mixed>, error?: mixed}
     */
    public function askAnalytics(string $spoergsmaal): array
    {
        // 🪤 `postEnvelope`, ikke `post`: svaret baerer sin daekning i `meta`,
        // og `post()` kasserer alt uden for `data`. Praecis den fejlklasse
        // `getEnvelope()` blev lavet for at undgaa paa laangiver-siden.
        return $this->postEnvelope('/v1/analytics/ask', ['spoergsmaal' => $spoergsmaal])
            ?? ['error' => 'no_response'];
    }

    public function fetchPropertyByAddress(string $address): array
    {
        $parsed = $this->parseAddress($address);

        return $this->fetchProperty($parsed);
    }

    /**
     * Kan denne adresse overhovedet oploeses til én matrikel?
     *
     * 🔑 ÉN definition, ikke fire. Kodebasen har allerede betalt for den fejl
     * med FIRE CPR-detektorer, hvor den fjerde accepterede et format de tre
     * andre afviste. `Search::search()` og `Lookup::mount()` spoerger nu samme
     * sted som chokepunktet.
     *
     * 🪤 KUN `zip`. Foerste udkast krævede ogsaa `number !== ''` — det AFVISTE
     * helt almindelige danske adresser, fordi `parseAddress()`s regex er
     * ankret til sidste token og ikke taaler et mellemrum foer bogstavet:
     *
     *   'Strandvejen 100 B, 2900 Hellerup'   -> number='' (men FULDT gyldig)
     *   'Vestergade 1 A, 5000 Odense C'      -> number=''
     *   'Bakkedraget 7 st tv, 8000 Aarhus C' -> number=''
     *
     * Maalt: alle tre naaede API'et og fik data FOER guarden. En tom `number`
     * er altsaa oftest en PARSER-begraensning, ikke et hul i adressen — og
     * upstream afgoer det bedre end vi kan. `zip` er derimod aegte paakraevet.
     */
    public function adresseKanOploeses(string $address): bool
    {
        return $this->parsetAdresseKanOploeses($this->parseAddress($address));
    }

    /**
     * Samme regel, men over den PARSEDE form.
     *
     * 🔑 To former, ÉN regel. `fetchProperty()` tager et array der lovligt kan
     * baere `matrikel_id` UDEN adresse — en streng-praedikat kan ikke udtrykke
     * det, saa at tvinge dem sammen ville enten braekke matrikel-opslag eller
     * gen-indfoere `number`-regressionen. De to former deler nu krop i stedet
     * for at vaere to uafhaengige formuleringer der kan drive fra hinanden.
     */
    public function parsetAdresseKanOploeses(array $parsed): bool
    {
        if (! empty($parsed['matrikel_id'])) {
            return true;
        }

        return ! empty($parsed['zip']);
    }

    /**
     * 🚨 CHOKEPUNKTET. Her — ikke i `resolveAddressAnalysis()`.
     *
     * Foerste udkast lagde guarden i `resolveAddressAnalysis()` og kaldte den
     * "det eneste punkt alle 14 kaldesteder deler". Det var forkert, og
     * maalefejlen er vaerd at huske: jeg greppede efter METODENAVNET og fandt
     * 14 kaldere — men `fetchPropertyByAddress()` rammer samme endpoint UDEN
     * at gaa gennem den metode, saa den var per konstruktion usynlig for min
     * soegning. Maalt:
     *
     *   fetchPropertyByAddress('Søndergade 43A')
     *   => POST /v1/property/analysis {"street":…,"number":"43A","zip":""}
     *
     * 🔑 Spoerg ikke "hvem kalder metoden?" men "hvem rammer ENDPOINTET?".
     * `grep "v1/property/analysis"` fandt den paa ét sekund.
     *
     * `fetchProperty()` er det ENESTE sted der poster til endpointet — en
     * test pinner det (`AdresseChokepunktTest`), saa doer nr. 16 ikke kan
     * snige sig ind.
     */
    public function fetchProperty(array $searchData): array
    {
        // Upstream: "The matrikel id field is required when street / number /
        // zip is not present". Samme gadeadresse findes typisk flere steder i
        // landet, saa uden postnummer kan den ikke oploeses.
        if (! $this->parsetAdresseKanOploeses($searchData)) {
            return ['error' => 'address_ambiguous', 'status' => 422];
        }

        $result = $this->post('/v1/property/analysis', $searchData);

        if (isset($result['error'])) {
            return $result;
        }

        $prop = $result['property'] ?? [];

        if (empty($prop)) {
            return [];
        }

        $bbr = $prop['bbr'] ?? [];
        $val = $prop['valuation'] ?? null;
        $owners = $prop['owners'] ?? [];

        $transformed = [
            'property' => [
                'address' => trim(($prop['address'] ?? '').', '.($prop['postal_code'] ?? '').' '.($prop['city'] ?? ''), ', '),
                'matrikel' => $prop['matrikel_id'] ?? null,
                'bfe' => $prop['matrikel']['bfe'] ?? $prop['matrikel_id'] ?? null,
                'type' => $bbr['usage_code'] ?? null,
                'area' => $bbr['total_area'] ?? null,
                'built' => $bbr['year_built'] ?? null,
            ],
        ];

        if ($val) {
            $transformed['valuation'] = [
                'property_value' => $val['estimated_value'] ?? 0,
                'land_value' => $val['land_value'] ?? 0,
                'year' => substr($val['date'] ?? $val['valuation_date'] ?? '', 0, 4),
            ];
        }

        if (! empty($owners)) {
            $owner = $owners[0];
            $transformed['owner'] = [
                'name' => $owner['name'] ?? 'Ukendt',
                'cvr' => $owner['identifier'] ?? $owner['cvr_nr'] ?? null,
            ];
        }

        $companies = $prop['companies_at_address'] ?? [];
        if (! empty($companies)) {
            $transformed['companies_at_address'] = collect($companies)->map(fn ($c) => [
                'name' => $c['name'] ?? '',
                'cvr' => $c['cvr'] ?? '',
            ])->all();
        }

        return $transformed;
    }

    public function fetchValuation(string $matrikelId): ?array
    {
        return $this->get("/v1/valuations/{$matrikelId}");
    }

    /**
     * 🚨 EN FORSLAGSLISTE ER ALDRIG EN FEJL-ARRAY — filtret hoerer hjemme HER.
     *
     * `get()` sender en RequestException gennem `errorFrom()`, som RETURNERER
     * `['error' => 'upstream_error', 'status' => 500]`. `rescue()` fanger den
     * ikke (intet kastes), og arrayen har `count() == 2`, saa enhver
     * `@if(count($forslag) > 0) @foreach` itererer dens VAERDIER.
     *
     * Maalt 20/8 — fire forbrugere, ingen af dem filtrerede:
     *   Index.php:38,91    -> index.blade.php:25 laeser {{ $suggestion['tekst'] }}
     *                         UDEN `??` => TypeError, FORSIDEN gaar ned
     *   Search.php:161,283 -> to tomme knapper under "Mente du:"
     *
     * 🔑 PR #179 rettede det i `Lookup` ALENE. Det er samme fejl som guarden
     * paa hver doer: det femte kaldested arver faelden. Her kan det ikke ske —
     * ingen forbruger vil nogensinde have en fejl-array som forslagsliste.
     *
     * 🪤 `is_scalar($r['tekst'])` med: en raekke med et array i feltet gav
     * "Array to string conversion" i bladen og tog hele siden ned.
     *
     * @return list<array{tekst: string, ...}> Tom liste = intet brugbart svar.
     */
    public function addressAutocomplete(string $query, int $limit = 10): array
    {
        $svar = $this->get('/v1/map/autocomplete', ['q' => $query, 'limit' => $limit]) ?? [];

        if (isset($svar['error'])) {
            return [];
        }

        return array_values(array_filter(
            $svar,
            fn ($r) => is_array($r) && ! empty($r['tekst']) && is_scalar($r['tekst'])
        ));
    }

    /**
     * Baglaens laangiver-opslag: hvor er DENNE laangiver eksponeret?
     *
     * 🚨 Kan IKKE erstattes af en kreditor-soegning. Ved et ejerpantebrev er
     * selskabet SELV anfoert som kreditor — maalt paa Akacietorvet staar der
     * `AKACIETORVET ApS` paa begge, mens de faktiske laangivere (Ringkjoebing
     * Landbobank, Draupnir, Omega) kun findes i `UnderpantrettighedSamling`.
     * Feltet er offentligt efter tinglysningslovens § 1 a, stk. 1.
     *
     * ⚠️ Svarets `meta.disclaimer` SKAL vises sammen med beloebet. Summen er
     * hvad laangiveren staar ANFOERT for i registret; en panthaver kan
     * optraede paa vegne af andre kreditorer, saa det er ikke noedvendigvis
     * egen finansiering. Uden forbeholdet laeses tallet som en paastand om
     * deres balance.
     *
     * @return array{
     *   lender_name: ?string, cvr: string, documents: int, properties: int,
     *   total_kr: int, rows: array<int, array<string, mixed>>, truncated: bool,
     *   meta?: array<string, string>
     * }|null
     */
    public function fetchLenderExposure(string $cvr): ?array
    {
        // 🪤 `get()` returnerer `json('data')`, saa forbeholdet i `meta` ville
        // gaa tabt. Det maa ikke kunne ske ved et uheld — derfor hentes hele
        // svaret og `meta` foldes ind i det returnerede array.
        $response = $this->getEnvelope("/v1/lender-exposure/{$cvr}");

        if ($response === null || isset($response['error'])) {
            return $response;
        }

        return ($response['data'] ?? []) + ['meta' => $response['meta'] ?? []];
    }

    public function getMapLayers(): array
    {
        return $this->get('/v1/map/layers') ?? [];
    }

    public function fetchCrossOwnership(array $cvrs): array
    {
        return $this->post('/v1/cvr/cross-ownership', ['cvr_numbers' => $cvrs]);
    }

    /**
     * Cached variant, mirroring fetchCompaniesByCprCached()'s contract (5 min
     * TTL, failures NEVER cached).
     *
     * PersonStructure calls this from mount AND from rehydrateBeforeRebuild(),
     * which every poll tick, chip toggle and expand runs through — so the
     * uncached version refired an identical POST roughly 10-12 times on an
     * ordinary page view, for a relationship set that cannot change between
     * them.
     *
     * The key is the SET of cvrs, sorted: the caller derives the list from
     * graph order, which shifts as the graph grows, and an order-sensitive key
     * would miss on every reordering — cache-missing precisely when the page
     * is busiest. sha1 keeps the key bounded no matter how many cvrs a person
     * has (the cap is 20, so the raw list would otherwise run to ~180 chars).
     *
     * A failure must not be cached: post() returns ['error' => …] rather than
     * throwing, and in PersonStructure a cross-ownership failure fails the
     * WHOLE skeleton — caching it would leave the retry button dead for the
     * full TTL.
     */
    public function fetchCrossOwnershipCached(array $cvrs): array
    {
        $sorted = collect($cvrs)->map(fn ($cvr) => (string) $cvr)->unique()->sort()->values()->all();
        $cacheKey = 'metis:cross_ownership:'.sha1(implode(',', $sorted));

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $result = $this->fetchCrossOwnership($cvrs);

        if (! isset($result['error'])) {
            Cache::put($cacheKey, $result, 300);
        }

        return $result;
    }

    public function fetchRolesByCvr(array $cvrs, ?string $excludeCpr = null): array
    {
        $payload = ['cvr_numbers' => $cvrs];
        if ($excludeCpr) {
            $payload['exclude_cpr'] = $excludeCpr;
        }

        return $this->post('/v1/cvr/roles-by-cvr', $payload);
    }

    public function fetchCompanyInfo(string $cvr): ?array
    {
        $cacheKey = $this->companyInfoCacheKey($cvr);

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        try {
            $response = $this->client()
                ->get("/v1/cvr/company/{$cvr}");

            $company = $response->json('data.company');
        } catch (\Throwable $e) {
            return null;
        }

        // Kun cache et rigtigt svar — en fejl eller manglende data må ikke
        // skygge for friske data i 24t (samme mønster som property-portfolio).
        if ($company) {
            Cache::put($cacheKey, $company, 86400);
        }

        return $company;
    }

    /**
     * Pooled variant af fetchCompanyInfo() til graf-berigelse: slår cache op
     * pr. cvr FØRST, henter kun de manglende samtidigt via Http::pool, og
     * returnerer en map cvr => company-array|null.
     *
     * Uafhængige kald (modsat fetchPropertiesBatch's alt-eller-intet): ét
     * cvr's 500'er eller timeout må ikke koste de andre kortene i grafen —
     * det svarer til hvordan komponenten allerede itererer selskaber
     * enkeltvis via fetchCompanyInfo(), blot samtidigt.
     *
     * Pool-requests kan ikke genbruge en PendingRequest fra client() (poolen
     * bygger sine egne requests via closure), så base-url/token/headers
     * genskabes eksplicit på hver pool-request.
     */
    public function fetchCompanyInfosPooled(array $cvrs): array
    {
        if (empty($cvrs)) {
            return [];
        }

        $cvrs = array_values(array_unique($cvrs));

        $results = [];
        $missing = [];

        foreach ($cvrs as $cvr) {
            $cacheKey = $this->companyInfoCacheKey($cvr);

            if (! is_null($cached = Cache::get($cacheKey))) {
                $results[$cvr] = $cached;
            } else {
                $missing[] = $cvr;
            }
        }

        if (empty($missing)) {
            return $results;
        }

        $token = session('metis_user_token') ?: config('metis.registry_api.key');
        $baseUrl = config('metis.registry_api.url');

        $responses = Http::pool(fn ($pool) => collect($missing)
            ->map(fn ($cvr) => $pool->as($cvr)
                ->withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->baseUrl($baseUrl)
                ->get("/v1/cvr/company/{$cvr}"))
            ->all(), concurrency: self::POOL_CONCURRENCY);

        foreach ($missing as $cvr) {
            $response = $responses[$cvr] ?? null;

            try {
                $company = $response instanceof \Illuminate\Http\Client\Response && $response->successful()
                    ? $response->json('data.company')
                    : null;
            } catch (\Throwable $e) {
                $company = null;
            }

            if ($company) {
                Cache::put($this->companyInfoCacheKey($cvr), $company, 86400);
            }

            $results[$cvr] = $company;
        }

        return $results;
    }

    /**
     * CACHE-ONLY variant of fetchCompanyInfosPooled(): returns cvr => company
     * for the cvrs whose 24h company-info cache is still warm, and simply
     * OMITS the rest. Issues no HTTP request under any circumstance.
     *
     * For recovery paths running inside INTERACTIVE requests (PersonStructure's
     * chip toggle / expand, via recoverEnrichmentResults()). Those must not
     * turn a click into a pooled network pass whose size grows with the number
     * of companies in the graph — a cold cache would make an interaction that
     * is normally instant hang on a fan-out of upstream calls. The caller
     * treats an incomplete map as "not recoverable cheaply" and hands the phase
     * back to the poll loop, which fetches it properly a tick later.
     *
     * @return array<string, array>
     */
    public function fetchCompanyInfosCached(array $cvrs): array
    {
        $results = [];

        foreach (array_unique($cvrs) as $cvr) {
            $cached = Cache::get($this->companyInfoCacheKey((string) $cvr));

            if (! is_null($cached)) {
                $results[(string) $cvr] = $cached;
            }
        }

        return $results;
    }

    /**
     * 🚨 DELIBERATELY TENANT-NEUTRAL — not an oversight, and not to be "fixed"
     * by adding the token to the key without reading this first.
     *
     * The key carries no token/user/session component, so every caller shares
     * one entry per cvr. That is correct because company-info is CVR REGISTER
     * data: public, identical for every caller, and not derived from who asked.
     * The token is a QUOTA IDENTITY (who is billed, who is rate-limited), not
     * an authorisation scope — two tokens asking about the same cvr are
     * entitled to byte-identical answers.
     *
     * Namespacing per token would multiply the cache by the number of tokens
     * and turn a warm shared cache into N cold ones, for no privacy gain.
     *
     * WHEN TO REVISIT: the moment registry-api returns anything token-SCOPED on
     * this endpoint — a per-customer note, an entitlement flag, a field one
     * tenant sees and another does not. At that point the shared entry leaks
     * one tenant's view to another and the key MUST gain a token component.
     * There is a test pinning this decision so the change is a conversation
     * rather than an accident.
     */
    protected function companyInfoCacheKey(string $cvr): string
    {
        return "metis:company_info:{$cvr}";
    }

    public function fetchCompanyStructure(string $cvr): array
    {
        return $this->post('/v1/cvr/company-structure', ['cvr' => $cvr]) ?? [];
    }

    /**
     * Cachet variant af fetchCompanyStructure() — bruges KUN når enrichment
     * ikke kører (se komponentens $enriching-flag). Mens enrichment kører
     * skal den ucachede fetchCompanyStructure() bruges, ellers fryser
     * datter-væksten i 5 minutter.
     */
    public function fetchCompanyStructureCached(string $cvr): array
    {
        if (! is_null($cached = Cache::get(self::structureCacheKey($cvr)))) {
            return $cached;
        }

        $structure = $this->fetchCompanyStructure($cvr);
        $this->cacheStructure($cvr, $structure);

        return $structure;
    }

    /**
     * CACHE-ONLY variant of fetchCompanyStructureCached() — whose name promises
     * a cache but which falls through to a real POST on a miss. This one never
     * does: a miss returns null and the caller decides.
     *
     * For recovery paths inside INTERACTIVE requests; see
     * fetchCompanyInfosCached() for the full rationale.
     */
    public function fetchCompanyStructureFromCache(string $cvr): ?array
    {
        return Cache::get(self::structureCacheKey($cvr));
    }

    /**
     * 🚨 Tenant-neutral by the same deliberate decision as
     * companyInfoCacheKey() — see that docblock for the full reasoning and for
     * the condition under which this must change (any token-SCOPED field
     * appearing on the endpoint).
     */
    protected static function structureCacheKey(string $cvr): string
    {
        return "metis:company_structure:{$cvr}";
    }

    /**
     * Cache kun et ikke-tomt svar — se noten ved fetchCompanyPropertyPortfolio:
     * en fejl (eller et tomt svar) må ikke skygge for friske data i 5 minutter.
     * Delt af den cachede og den pooled'e henter, så key, TTL og tom-reglen kun
     * findes ét sted.
     */
    protected function cacheStructure(string $cvr, ?array $structure): void
    {
        // 🪰 `! isset(...['error'])` er IKKE redundant med `! empty()`:
        // et fejl-array ER non-empty. Docblocken ovenfor lovede allerede at
        // "en fejl ikke maa skygge for friske data i 5 minutter", men koden
        // holdt det ikke — den cachede fejlen som var den et svar.
        // Blev foerst FARLIGT da post() holdt op med at kaste paa misdannede
        // svar: foer kastede en TypeError, saa der var intet at cache.
        // De tre soeskende (fetchCrossOwnershipCached:458,
        // fetchCompaniesByCprCached, fetchPersonPropertyPortfolioByCprCached)
        // gjorde det rigtigt hele tiden. Review-fund 18/8.
        if (! empty($structure) && ! isset($structure['error'])) {
            Cache::put(self::structureCacheKey($cvr), $structure, 300);
        }
    }

    /**
     * Pooled variant af fetchCompanyStructure() til fase 2's graf-udvidelse:
     * hvert cvr hentes samtidigt via Http::pool, præcis én gang pr. kald.
     *
     * Cache-kontrakten er ASYMMETRISK og bevidst: LÆSER aldrig (fase 2 skal
     * have hvert cvr friskt), men SKRIVER via cacheStructure(), så den
     * efterfølgende rehydrering bliver et cache-hit. Uden den skrivning ramte
     * PersonStructures per-tick-gendannelse netværket igen for hvert allerede
     * hentet cvr — ~20 ekstra POSTs pr. 2-sekunders tick ved first-level-loftet.
     *
     * Samme uafhængigheds-garanti som fetchCompanyInfosPooled(): ét cvr's
     * fejl giver null for det cvr, aldrig en exception ud til kalderen.
     */
    public function fetchCompanyStructuresPooled(array $cvrs): array
    {
        if (empty($cvrs)) {
            return [];
        }

        $cvrs = array_values(array_unique($cvrs));

        $token = session('metis_user_token') ?: config('metis.registry_api.key');
        $baseUrl = config('metis.registry_api.url');

        $responses = Http::pool(fn ($pool) => collect($cvrs)
            ->map(fn ($cvr) => $pool->as($cvr)
                ->withToken($token)
                ->acceptJson()
                ->timeout(30)
                ->baseUrl($baseUrl)
                ->post('/v1/cvr/company-structure', ['cvr' => $cvr]))
            ->all(), concurrency: self::STRUCTURE_POOL_CONCURRENCY);

        $results = [];

        foreach ($cvrs as $cvr) {
            $response = $responses[$cvr] ?? null;

            try {
                $results[$cvr] = $response instanceof \Illuminate\Http\Client\Response && $response->successful()
                    ? $response->json('data')
                    : null;
            } catch (\Throwable $e) {
                $results[$cvr] = null;
            }

            $this->cacheStructure((string) $cvr, $results[$cvr]);
        }

        return $results;
    }

    /**
     * Batch-opslag af ejendomme via matrikel-id. Chunker i grupper à 200
     * (backend-grænse) og fladgør 'data'-listerne fra hver chunk til én liste.
     *
     * Alt-eller-intet: post()-hjælperen returnerer ['error' => ..., 'status' => ...]
     * ved HTTP-fejl (aldrig null), så et fejlende chunk kan ikke bare
     * flatMap'es ind — det ville blande fejl-strenge ind mellem gyldige
     * properties. Fejler ét chunk, returnér null for hele kaldet: Task 7's
     * fejl-flow behandler null som "berigelse fejlede", mens et stille
     * delvist resultat ville give ejendoms-noder uden anvendelse uden
     * forklaring.
     */
    public function fetchPropertiesBatch(array $matrikelIds): ?array
    {
        if (empty($matrikelIds)) {
            return [];
        }

        $properties = [];

        foreach (collect($matrikelIds)->chunk(200) as $chunk) {
            $response = $this->post('/v1/properties/batch', [
                'matrikel_ids' => $chunk->values()->all(),
            ]);

            if (! is_array($response) || isset($response['error'])) {
                return null;
            }

            array_push($properties, ...$response);
        }

        return $properties;
    }

    /**
     * Aktiepost-relationer (begge retninger) — SEPARAT endpoint fra structure,
     * så relations aldrig blandes i ejendoms-/gæld-aggregering (Option B).
     */
    public function fetchCompanyRelations(string $cvr): array
    {
        return $this->post('/v1/cvr/company-relations', ['cvr' => $cvr]) ?? [];
    }

    public function fetchFundingHistory(string $cvr): ?array
    {
        try {
            return $this->client()
                ->get("/v1/company/{$cvr}/funding-history")
                ->throw()
                ->json('data');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * CACHE-ONLY variant of fetchCompanyPropertyPortfolio(): returns the cached
     * payload if the 5-min entry is still warm, and null otherwise. Issues no
     * HTTP request under any circumstance.
     *
     * Sibling of fetchCompanyInfosCached(), and there for the same reason: the
     * recovery paths run inside INTERACTIVE requests (PersonStructure's chip
     * toggle / expand), where a cache miss must cost nothing. The caller
     * downgrades the cvr and lets the poll loop fetch it properly a tick later
     * — see recoverPropertyResults().
     *
     * Shares the key with the fetching variant rather than deriving its own, so
     * the two cannot drift apart over a limit/offset default.
     */
    public function fetchCompanyPropertyPortfolioCached(string $cvr, int $limit = 25, int $offset = 0): ?array
    {
        return Cache::get(self::propertyPortfolioCacheKey($cvr, $limit, $offset));
    }

    protected static function propertyPortfolioCacheKey(string $cvr, int $limit, int $offset): string
    {
        return "metis:company_property_portfolio:{$cvr}:{$limit}:{$offset}";
    }

    public function fetchCompanyPropertyPortfolio(string $cvr, int $limit = 25, int $offset = 0): ?array
    {
        $cacheKey = self::propertyPortfolioCacheKey($cvr, $limit, $offset);

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        try {
            $result = $this->client()
                ->timeout(30)
                ->get("/v1/company/{$cvr}/property-portfolio", [
                    'limit' => $limit,
                    'offset' => $offset,
                ])
                ->throw()
                ->json('data');
        } catch (\Throwable $e) {
            return null;
        }

        // Cache kun færdige porteføljer. Fejl og 'building'-svar må ikke
        // skygge for friske data i 5 min mens backend-jobbet bygger cachen.
        if (! empty($result['portfolio']['properties'])) {
            Cache::put($cacheKey, $result, now()->addMinutes(5));
        }

        return $result;
    }

    /**
     * Fetch tinglysning-overview for a company (root + descendant koncerntræ).
     *
     * Returns the raw API shape per spec:
     * [
     *   'company' => ['cvr' => '...', 'name' => '...'],
     *   'tree_meta' => [...],
     *   'tier_breakdown' => [...],
     *   'mortgages_added' => [...],
     *   'streaming' => ['complete' => bool, 'cursor' => '...', 'total_expected' => N, 'delivered_so_far' => M],
     * ]
     *
     * Returns null on transport/HTTP failure (caller renders error-state).
     *
     * @param  array<string,mixed>  $filters  status, mortgage_types[], min_amount, max_amount, sort, tree_depth
     * @param  string|null  $cursor  Opaque cursor for delta-streaming continuation
     */
    public function fetchCompanyTinglysningOverview(
        string $cvr,
        array $filters = [],
        ?string $cursor = null,
    ): ?array {
        $cacheKey = "metis:tinglysning_overview:{$cvr}:" . md5(json_encode([$filters, $cursor]));

        if ($cached = Cache::get($cacheKey)) {
            return $cached;
        }

        $query = array_filter([
            ...$filters,
            'cursor' => $cursor,
        ], fn ($v) => $v !== null && $v !== '');

        try {
            $result = $this->client()
                ->timeout(15)
                ->get("/v1/companies/{$cvr}/tinglysning-overview", $query)
                ->throw()
                ->json();
        } catch (\Throwable $e) {
            // Cach ALDRIG en fejl. Note: Cache::remember() cacher aldrig en null
            // callback-retur (Repository::remember() tjekker kun is_null() på
            // det LÆSTE hit, ikke på det der skulle skrives) — en fejl kunne
            // altså ikke tidligere "sidde fast" som cachet null. Catch'en ligger
            // her for at undgå et unødigt Cache::put($key, null, 5 min) ved
            // HVER fejl, ikke for at forhindre en stale-fejl-replay.
            return null;
        }

        Cache::put($cacheKey, $result, now()->addMinutes(5));

        return $result;
    }

    public function getEnrichmentStatus(string $cvr): ?array
    {
        try {
            $response = $this->client()
                ->timeout(5)
                ->get("/v1/enrichment/{$cvr}/status");

            if (! $response->successful()) {
                return null;
            }

            return $response->json('data');
        } catch (\Throwable) {
            return null;
        }
    }

    public function fetchCompanyTaxRecords(string $cvr): ?array
    {
        try {
            return $this->client()
                ->get("/v1/company/{$cvr}/tax")
                ->throw()
                ->json('data');
        } catch (RequestException $e) {
            return null;
        }
    }

    public function fetchCompaniesByCpr(string $cpr): ?array
    {
        return $this->post('/v1/cvr/search-by-cpr', ['cpr' => $cpr]);
    }

    /**
     * Companies for a person looked up by NAME (no CPR) — same shape as the
     * CPR path so PersonStructure can classify() it unchanged.
     *
     * post() never returns null itself: a RequestException is caught by
     * errorFrom() and turned into ['error' => 'upstream_error', 'status' =>
     * $e->getCode()], and a ConnectionException into ['error' =>
     * 'upstream_error', 'status' => 0] via transportErrorFrom(). Those two
     * failure modes mean different things to the caller, so they're kept
     * distinct here rather than both collapsing to one shape:
     *
     * - status 404 ⇒ the participant genuinely doesn't exist in CVR. This is
     *   NOT a failure — it's a real, settled answer of zero companies. Mapped
     *   to ['companies' => []] so the caller reaches its ordinary EMPTY state
     *   (skeletonStatus = 'empty') rather than its FAILED state. Returning
     *   null here previously made PersonStructure::attempt() normalise it to
     *   a failure, so a name that simply isn't in CVR rendered "Selskabsrelationerne
     *   kunne ikke hentes." with a retry button that could never succeed —
     *   the exact "findes ikke ligner kunne ikke hentes" bug this guards
     *   against now.
     * - any other error (incl. status 0 for a transport failure) ⇒ returned
     *   as-is, the ['error' => ...] shape. The caller must NOT read this as
     *   "no companies" — a transport failure is genuinely retryable, unlike a
     *   404, so it must keep failing PersonStructure::attempt() into 'failed'.
     */
    public function fetchCompaniesByName(string $name): ?array
    {
        $result = $this->post('/v1/cvr/person-companies-by-name', ['name' => $name]);

        // 'status' alone is not a safe discriminator: it's already a business
        // field elsewhere in this class (company['status'] === 'NORMAL' in
        // searchPersonByName()/fetchCompany()), and nothing stops a success
        // payload from carrying a 'status' key of its own. Only errorFrom()
        // and transportErrorFrom() set 'error' — gate on that first.
        if (isset($result['error']) && ($result['status'] ?? null) === 404) {
            return ['companies' => []];
        }

        return $result;
    }

    /**
     * Cachet variant af fetchCompaniesByCpr() — nøglen hashes (sha1) så et
     * rå CPR-nummer aldrig havner i cache-nøglen eller -loggen. 5 min TTL:
     * lang nok til at dæmpe gentagne opslag under samme graf-udvidelse, kort
     * nok til at nye selskabsregistreringer dukker op hurtigt. Fejl caches
     * ALDRIG — se noten ved fetchCompanyInfo().
     */
    public function fetchCompaniesByCprCached(string $cpr): ?array
    {
        $cacheKey = 'metis:companies_by_cpr:'.sha1($cpr);

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $result = $this->fetchCompaniesByCpr($cpr);

        // post()'s catch-block returnerer aldrig null ved en RequestException
        // — den giver et ['error' => ..., 'status' => ...]-array. Den fejlform
        // skal behandles som "fejlede" på samme måde som et rent null-svar,
        // ellers cacher vi en 500'er i 5 minutter.
        if (! is_null($result) && ! isset($result['error'])) {
            Cache::put($cacheKey, $result, 300);
        }

        return $result;
    }

    public function fetchPropertiesByCpr(string $cpr): ?array
    {
        return $this->post('/v1/property-tinglysning/search-by-cpr', ['cpr' => $cpr]);
    }

    public function fetchPersonRoles(string $query): ?array
    {
        return $this->post('/v1/cvr/person-roles', ['name' => $query]);
    }

    /**
     * F6: Fetch comparable sales for a given BFE.
     */
    public function fetchSimilarSales(string $bfe, array $opts = []): ?array
    {
        try {
            return $this->client()
                ->get("/v1/properties/{$bfe}/similar-sales", array_filter([
                    'radius_km' => $opts['radius_km'] ?? null,
                    'area_pct' => $opts['area_pct'] ?? null,
                    'months_back' => $opts['months_back'] ?? null,
                ]))
                ->throw()
                ->json('data');
        } catch (\Throwable $e) {
            return null;
        }
    }

    /**
     * Aggregated property portfolio joined across all companies where this
     * person is Reelle ejere / EJERREGISTER. Server-side cached 6h per name.
     * Slow on first call (5-15s for ~10 companies), instant after cache.
     */
    public function fetchPersonPropertyPortfolio(string $name): ?array
    {
        try {
            $response = $this->client()
                ->timeout(60)
                ->post('/v1/cvr/person-property-portfolio', ['name' => $name]);

            // Tjek status FØR ->throw(): 404 er et svar vi vil behandle,
            // ikke en fejl vi vil kaste på.
            //
            // 404 betyder at NAVNEOPSLAGET ikke fandt en person
            // (CvrController::personPropertyPortfolioByName: searchPersonRolesByName()
            // gav falsy). Det er "vi slog ikke op" — IKKE "personen har ingen
            // ejendomme". Returnér derfor en egen markør, ikke en tom
            // portefølje: en tom portefølje ville få viewet til at påstå
            // fravær, og knappen vises kun når vi ALLEREDE har skrevet
            // "N ejendomme via M selskaber" paa skærmen. Det ville være en falsk
            // autoritativ benægtelse — den værste fejlmodus i due diligence.
            //
            if ($response->status() === 404) {
                return ['not_found' => true];
            }

            return $response->throw()->json('data');
        } catch (ConnectionException|RequestException $e) {
            return null;   // ægte fejl — konsumenten må tilbyde retry
        }
    }

    public function fetchPersonPropertyPortfolioByCpr(string $cpr): ?array
    {
        return $this->post('/v1/person/property-portfolio', ['cpr' => $cpr]);
    }

    /**
     * Cachet variant af fetchPersonPropertyPortfolioByCpr() — spejler
     * fetchCompaniesByCprCached() præcist: nøglen hashes (sha1) så et rå
     * CPR-nummer aldrig havner i cache-nøglen eller -loggen, 300s TTL, og
     * fejl caches ALDRIG. post()'s catch-block returnerer aldrig null ved en
     * RequestException — den giver et ['error' => ..., 'status' => ...]-array
     * — den fejlform skal behandles som "fejlede" på samme måde som et rent
     * null-svar, ellers cacher vi en 500'er (eller transportfejl) i 5 minutter.
     */
    public function fetchPersonPropertyPortfolioByCprCached(string $cpr): ?array
    {
        $cacheKey = self::personPropertyPortfolioCacheKey($cpr);

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $result = $this->fetchPersonPropertyPortfolioByCpr($cpr);

        if (! is_null($result) && ! isset($result['error'])) {
            Cache::put($cacheKey, $result, 300);
        }

        return $result;
    }

    /**
     * CACHE-ONLY variant, the exact counterpart of
     * fetchCompanyStructureFromCache(): a miss returns null and the caller
     * decides, rather than falling through to the real POST the way the
     * ...Cached() method above does.
     *
     * For recovery paths that run inside INTERACTIVE requests (a chip toggle,
     * an expand). This endpoint is the most expensive one in the package —
     * 5-15s on a cold call — so a recovery pass allowed to fall through would
     * make a single chip click hang for that long, which is the whole reason
     * the cache-only/never-fetch split exists.
     */
    public function fetchPersonPropertyPortfolioByCprFromCache(string $cpr): ?array
    {
        return Cache::get(self::personPropertyPortfolioCacheKey($cpr));
    }

    /** sha1'd so a raw CPR never lands in a cache key, a log line or a dump. */
    protected static function personPropertyPortfolioCacheKey(string $cpr): string
    {
        return 'metis:person_property_portfolio:'.sha1($cpr);
    }

    /**
     * Resolve address to property analysis with caching.
     * Merged from MetisInputDetector::resolveAddressAnalysis().
     *
     * 🚨 FEJL OG TOM ER IKKE DET SAMME — og var det indtil 18/8.
     *
     * Metoden returnerede `[]` for BEGGE udfald, saa de 12 address-sektioner
     * der forbruger den ikke kunne skelne "vi spurgte og fik intet" fra "vi
     * kunne ikke spoerge". Resultatet var 12 sektioner der alle skrev
     * "Ingen data fundet" — og "Ingen pantebreve fundet" laeses som
     * GAELDFRIHED. I en kreditvurdering er det en konklusion nogen handler
     * paa. Observeret i prod: ét 422 (adresse uden postnummer) gav 12 falske
     * benaegtelser paa én side.
     *
     * 🪤 OG FEJLEN BLEV CACHET I 24 TIMER. `Cache::remember` wrappede hele
     * blokken, saa et enkelt daarligt svar laase loegnen fast et doegn.
     * Kodebasens egen regel siger det modsatte (#134: "cach aldrig fejl,
     * og stop polling mod doedt backend").
     *
     * Formen er BAGUDKOMPATIBEL: fejl-udfaldet baerer nu `error`/`status`,
     * men ingen `property`-noegle. Alle 12 sektioner laeser
     * `$analysis['property'][...] ?? <default>`, saa de opfoerer sig
     * praecis som foer indtil de opgraderes til at bruge fejl-tilstanden.
     * Referencemoenster: MapPanel::loadLayers() (samme mappe) og
     * MetisSection's afproevede hasError-kontrakt.
     *
     * @return array{}|array{error: string, status: int|null}|array<string, mixed>
     *   Tom liste = opslaget lykkedes, men ejendommen har ingen data.
     *   `['error' => ...]` = kaldet fejlede; sig ALDRIG "ingen data" paa den.
     */
    public function resolveAddressAnalysis(string $address): array
    {
        $cacheKey = 'metis:address_analysis:'.md5($address);

        if (! is_null($cached = Cache::get($cacheKey))) {
            return $cached;
        }

        $parsed = $this->parseAddress($address);

        // 🚨 Samme guard som `fetchProperty()` — men her SKAL den staa
        // eksplicit, fordi denne metode poster direkte (sektionerne har brug
        // for det RAA svar, ikke fetchProperty()'s transformerede form).
        // Begge veje bruger den samme praedikat, saa de ikke kan drive fra
        // hinanden. FOER cachen, saa en afvist adresse aldrig laases fast
        // (#134: "cach aldrig fejl").
        if (! $this->adresseKanOploeses($address)) {
            return ['error' => 'address_ambiguous', 'status' => 422];
        }

        // Return raw API response (not transformed) — sections need full data
        $result = $this->post('/v1/property/analysis', $parsed);

        // Transportfejl/4xx/5xx: giv fejlen VIDERE, og cach den ALDRIG.
        // post() konverterer en RequestException til ['error' => …, 'status' => …].
        //
        // 🪤 Foerste udkast havde ogsaa `$result === null` her, med en kommentar
        // om at null betyder "intet troevaerdigt svar". Den gren var UOPNAAELIG:
        // post() er `: array`, saa et null fra ->json('data') kastede en
        // TypeError FOER vi naaede hertil. Rettet ved kilden (`?? []`), ikke
        // med en gren der aldrig kunne koere.
        if (isset($result['error'])) {
            return [
                'error' => $result['error'] ?? 'transport_error',
                'status' => $result['status'] ?? null,
            ];
        }

        // 200 uden property = ejendommen findes, men vi har ingen data paa den.
        // DET er den aegte tomme tilstand, og den maa gerne caches.
        $svar = empty($result['property'] ?? null) ? [] : $result;

        Cache::put($cacheKey, $svar, now()->addHours(24));

        return $svar;
    }

    public function resolvePropertyComparison(string $query): ?array
    {
        // 🚨 SJETTE KOPI af praedikatet, fundet ved review 20/8. Den var en
        // haandrullet variant (`empty($parsed['zip'])`) mod et ANDET endpoint
        // (`property/compare`), uden for begge vagt-testers raekkevidde og
        // UDEN egen test: guarden kunne slettes helt uden at én af 804 tests
        // blev roed.
        //
        // 🪤 Den er i praksis uopnaaelig i dag, fordi `AddressComparison::mount()`
        // kalder `resolveAddressAnalysis()` FOERST og returnerer ved fejl. Men
        // den er beskyttet af en RAEKKEFOELGE i en kalder, ikke af sin egen
        // kontrakt — praecis saadan doer 15 og 16 opstod i denne fejlfamilie.
        if (! $this->adresseKanOploeses($query)) {
            return null;
        }

        $parsed = $this->parseAddress($query);

        $address = trim(($parsed['street'] ?? '') . ' ' . ($parsed['number'] ?? ''));
        $postalCode = $parsed['zip'];

        $cacheKey = "metis_comparison_{$postalCode}_{$address}";

        return Cache::remember($cacheKey, 3600, function () use ($address, $postalCode) {
            // Kun ConnectionException kan kastes her — se noten ved
            // property-portfolio ovenfor.
            try {
                return $this->client()
                    ->post('property/compare', [
                        'address' => $address,
                        'postal_code' => $postalCode,
                    ])->json('data');
            } catch (ConnectionException $e) {
                report($e);

                return null;
            }
        });
    }

    public function parseAddress(string $address): array
    {
        $parts = array_map('trim', explode(',', $address, 2));

        // "Bredgade 40" -> street=Bredgade, number=40
        preg_match('/^(.+?)\s+(\d+\S*)\s*$/', $parts[0], $matches);
        $street = $matches[1] ?? $parts[0];
        $number = $matches[2] ?? '';

        // "1260 København" -> zip=1260
        $zip = '';
        if (isset($parts[1])) {
            preg_match('/(\d{4})/', $parts[1], $zipMatch);
            $zip = $zipMatch[1] ?? '';
        }

        return ['street' => $street, 'number' => $number, 'zip' => $zip];
    }

    /**
     * 🚨 The error VALUE is a constant, never $e->getMessage(). RequestException
     * builds its message as "HTTP request returned status code N" plus the
     * upstream RESPONSE BODY (truncated to 120 chars) — and registry-api echoes
     * request input into some of its error bodies. On the CPR path that input is
     * the CPR, so getMessage() quietly turned every failed lookup into a return
     * value carrying the CPR onward to whatever logged, cached or rendered it.
     *
     * Nothing is lost: report() sends the REAL exception down the exception
     * channel, where the host-side Flare scrubber censors it. And no caller ever
     * read the string — every consumer in src/ isset()-checks the key and
     * substitutes its own user-facing message (grepped across the package),
     * which is exactly what makes a constant safe here.
     *
     * Used by EVERY catch site in this class, not just the two the CPR path
     * happens to run through: the shape is identical at all 13, and a helper
     * only half the file uses is an invitation to reintroduce the leak in the
     * next endpoint someone adds.
     */
    protected function errorFrom(RequestException $e): array
    {
        report($e);

        return ['error' => 'upstream_error', 'status' => $e->getCode()];
    }

    /**
     * ConnectionException er en SØSKENDE til RequestException (extends
     * Exception, ikke HttpClientException) — en timeout gik derfor LIGE
     * IGENNEM de her helpers og ramte 15 kaldsteder som uhåndteret exception
     * (Flare 9097433; PR #103 lukkede kun de 2 kaldsteder der selv omslutter
     * HTTP og stillede aldrig dette nabospørgsmål). Fanges nu til samme
     * bagudkompatible ['error' => ...]-shape som alle 13 forbrugere allerede
     * isset()-guarder på; status 0 = "intet svar modtaget".
     */
    protected function get(string $endpoint, array $query = []): ?array
    {
        try {
            return $this->client()
                ->get($endpoint, $query)
                ->throw()
                ->json('data');
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    /**
     * Som `get()`, men returnerer HELE svaret — ikke kun `data`.
     *
     * Findes fordi nogle endpoints baerer noget i `meta` der ikke maa falde paa
     * gulvet. Konkret: laangiver-eksponeringens forbehold om at beloebet er
     * hvad panthaveren staar ANFOERT for, ikke noedvendigvis egen finansiering.
     * Med `get()` ville `meta` vaere kasseret uden at nogen bemaerkede det.
     *
     * Samme fejlhaandtering som `get()` — bevidst, ikke kopieret ved et uheld:
     * en ConnectionException er en Exception, ikke en HttpClientException, og
     * gik derfor lige igennem helperne og ramte 15 kaldsteder som uhaandteret
     * exception (Flare 9097433). Den fejl skal ikke genindfoeres her.
     */
    protected function getEnvelope(string $endpoint, array $query = []): ?array
    {

        try {
            return $this->client()
                ->get($endpoint, $query)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    /**
     * Som `post()`, men returnerer HELE svaret — ikke kun `data`.
     *
     * 🚨 Findes fordi analyse-svar baerer deres DAEKNING i `meta`: hvor stor en
     * del af populationen spoergsmaalet faktisk kunne se. `post()` kasserer
     * det, og et praecist tal uden sit forbehold kan foere til en forkert
     * kreditbeslutning. Samme grund som `getEnvelope()` blev lavet til
     * laangiver-siden.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>|null
     */
    protected function postEnvelope(string $endpoint, array $data): ?array
    {
        try {
            return $this->client()
                ->post($endpoint, $data)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            // 🚨 ET 422 ER ET SVAR, IKKE EN FEJL. Analyse-endpointet bruger
            // 422 til at sige "jeg forstod ikke spoergsmaalet" og sender sin
            // EGEN forklaring med — fx "angiv et postnummer". `errorFrom()`
            // kasserer den og returnerer `upstream_error`, saa brugeren ville
            // se "der opstod en fejl" i stedet for at faa at vide hvad de
            // skulle rette. Samme fejlklasse som `get()` der kasserer `meta`.
            if ($e->response->status() === 422) {
                return $e->response->json();
            }

            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    /**
     * 🚨 `?? []` ER IKKE KOSMETIK. Metoden er deklareret `: array`, men
     * `->json('data')` giver NULL naar et 200-svar mangler `data`-noeglen
     * (tom body, `{"message":"ok"}`, `{"data":null}`). PHP kastede da en
     * TypeError FOER kalderen fik en chance for at reagere.
     *
     * 🪤 Og det var vaerre end det lyder: MetisSection er
     * `#[Lazy(isolate: false)]`, saa alle sektioner hentes i ÉT request.
     * Ét misdannet 200-svar tog derfor HELE adressesiden ned med en 500 —
     * ikke 12 sektioner med hver sin tomme tilstand. Vi haerdede 4xx/5xx
     * mens den skarpeste kant stod aaben.
     *
     * 🪤 `?? ['error' => …]`, IKKE `?? []`. Foerste rettelse gav en tom liste,
     * men et svar vi ikke forstaar er ikke "der er ingen data" — det er et
     * mislykket opslag. Med `[]` degraderede PersonStructure fra 'failed' til
     * 'empty', altsaa fra "kunne ikke hente" til "personen har ingen
     * selskaber". Det er praecis den falske benaegtelse hele denne PR handler
     * om, flyttet ét lag ned. Fanget af en eksisterende test (#118's
     * "malformed 200 response as a failure rather than a 500").
     *
     * Fundet ved review 18/8, verificeret med en probe.
     */
    protected function post(string $endpoint, array $data): array
    {
        try {
            return $this->client()
                ->post($endpoint, $data)
                ->throw()
                ->json('data') ?? ['error' => 'malformed_response', 'status' => null];
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    /**
     * Som errorFrom(): report() bevarer observability (en bar catch gør det
     * IKKE selv — det var rescue()'s eneste dyd), beskeden propagerer ALDRIG
     * (cURL-tekstens varierende ms ville splitte Flare-buckets, og den kan
     * bære upstream-echo af input).
     */
    protected function transportErrorFrom(ConnectionException $e): array
    {
        report($e);

        return ['error' => 'upstream_error', 'status' => 0];
    }

    /**
     * Debt-search endpoint returns the response shape at the root (not under 'data'),
     * so we bypass the get()/post() helpers which extract that key.
     */
    public function debtSearch(array $filters, ?string $source = null): array
    {
        $request = $this->client();
        if ($source !== null) {
            $request = $request->withHeaders(['X-Search-Source' => $source]);
        }

        try {
            return $request->get('/v1/debt-search', $filters)->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function createDebtSearchCsvLink(array $filters): array
    {
        try {
            return $this->client()
                ->post('/v1/debt-search/export-link', $filters)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    /**
     * "Udforsk" — geography-driven property search. POST (not GET) because the
     * polygon filter is a nested array. Geo filter is required server-side.
     */
    public function propertyExplore(array $filters, ?string $source = null): array
    {
        $request = $this->client();
        if ($source !== null) {
            $request = $request->withHeaders(['X-Search-Source' => $source]);
        }

        try {
            return $request->post('/v1/property-explore', $filters)->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function createPropertyExploreCsvLink(array $filters): array
    {
        try {
            return $this->client()
                ->post('/v1/property-explore/export-link', $filters)
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    // F1 Debt Alerts — watchlists + alerts endpoints

    public function listWatchlists(): array
    {
        try {
            return $this->client()->get('/v1/watchlists')->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    /**
     * F5 disambiguation: search CVR-deltagere by name, return candidates with
     * enhedsnummer + address + companies+roles for user to pick from.
     *
     * @return array{candidates: array, total: int}|array{error: string}
     */
    public function disambiguatePerson(string $name, int $limit = 20): array
    {
        try {
            return $this->client()
                ->get('/v1/cvr/person-disambiguate', ['name' => $name, 'limit' => $limit])
                ->throw()
                ->json('data');
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function checkBatch(array $items): array
    {
        try {
            return $this->client()
                ->post('/v1/watchlists/check-batch', ['items' => $items])
                ->throw()
                ->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function createWatchlist(string $type, string $value, ?string $label, array $alertTypes): array
    {
        try {
            return $this->client()->post('/v1/watchlists', [
                'watch_type' => $type,
                'watch_value' => $value,
                'display_label' => $label,
                'alert_types' => $alertTypes,
            ])->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function deleteWatchlist(int $id): array
    {
        try {
            return $this->client()->delete("/v1/watchlists/{$id}")->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function listAlerts(bool $unreadOnly = false, ?string $priority = null, int $page = 1): array
    {
        try {
            return $this->client()->get('/v1/alerts', array_filter([
                'unread_only' => $unreadOnly ? 1 : 0,
                'priority' => $priority,
                'page' => $page,
            ], fn ($v) => $v !== null))->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function markAlertRead(int $alertId): array
    {
        try {
            return $this->client()->patch("/v1/alerts/{$alertId}/read")->throw()->json();
        } catch (RequestException $e) {
            return $this->errorFrom($e);
        } catch (ConnectionException $e) {
            return $this->transportErrorFrom($e);
        }
    }

    public function getAlert(int $id): ?array
    {
        try {
            return $this->client()->get("/v1/alerts/{$id}")->throw()->json('data');
        } catch (\Throwable $e) {
            return null;
        }
    }
}
