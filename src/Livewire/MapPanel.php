<?php

namespace TheFountainhead\Metis\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Attributes\Renderless;
use Livewire\Component;
use TheFountainhead\Metis\Services\LookupAccess;
use TheFountainhead\Metis\Services\QuotaExceededException;
use TheFountainhead\Metis\Services\RegistryApi;
use TheFountainhead\Metis\View\Components\MetisLink;

class MapPanel extends Component
{
    public ?float $lat = null;
    public ?float $lng = null;
    public ?string $searchType = null;
    public array $markers = [];
    public array $layers = [];
    public bool $layersUnavailable = false;

    /** Adressen kortet blev bygget til. Klienten kan ændre den, så den stoles aldrig på uden gaten (se hentNaboer). */
    public ?string $query = null;

    /** Må brugeren udforske frit? Kun til visning; hentUdsnit() tjekker selv, hver gang. */
    public bool $kanUdforske = false;

    /** Må brugeren hente naboejendomme? Kun til visning; hentNaboer() tjekker selv, hver gang. */
    public bool $kanNaboer = false;

    /** Udsnit: loft for kortets udstrækning (grader) og for antal sider pr. udsnit. */
    public const UDSNIT_MAKS_BREDDE_LAT = 0.04;

    public const UDSNIT_MAKS_BREDDE_LNG = 0.08;

    /** Registry-api giver højst 50 pr. side; udsnittet henter højst tre sider (150 ejendomme). */
    public const UDSNIT_SIDE = 50;

    public const UDSNIT_SIDER = 3;

    /** Udsnittet snappes til dette gitter (grader), så små panoreringer rammer cachen. */
    public const UDSNIT_GITTER = 0.002;

    /** Kortet henter først ejendomme fra dette zoomniveau (bruges af kortets JS). */
    public const UDSNIT_MIN_ZOOM = 16;

    /** Radier (meter) brugeren kan vælge til naboejendomme. */
    public const NABO_RADIER = [100, 250, 500];

    /** Forvalgt radius; bruges af både serveren og kortets kontrol. */
    public const NABO_STANDARD_RADIUS = 250;

    /** Så mange ejendomme hentes højst pr. opslag (registry-apis loft er 50). */
    public const NABO_MAKS = 50;

    public function mount(?float $lat = null, ?float $lng = null, ?string $searchType = null, ?string $query = null): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->searchType = $searchType;
        $this->query = $query;
        $this->kanUdforske = app(LookupAccess::class)->harPilotAdgang();
        $this->kanNaboer = app(LookupAccess::class)->erKvoteFritaget();

        // Resolve address query to coordinates if lat/lng not provided
        if (! $this->lat && $query) {
            $analysis = rescue(fn () => app(RegistryApi::class)->resolveAddressAnalysis($query));
            $coords = $analysis['property']['coordinates'] ?? null;
            if ($coords) {
                $this->lat = (float) ($coords['lat'] ?? $coords['latitude'] ?? 0);
                $this->lng = (float) ($coords['lng'] ?? $coords['longitude'] ?? 0);
            }
            $this->searchType = 'address';
        }

        $this->loadLayers();
    }

    #[On('search-completed')]
    public function onSearchCompleted($lat, $lng, $type, $markers = []): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->searchType = $type;
        $this->markers = $markers;
        // Kortet flyttes til noget andet end adressen; naboopslaget hører til den gamle.
        $this->query = null;
        $this->dispatch('map-update');
    }

    /**
     * Ejendomme omkring adressen: adresse, byggeår, bygningsareal og vurdering.
     *
     * 🚨 Gaten ligger HER og ikke i mount(): en offentlig Livewire-metode kan kaldes
     * af klienten med et gammelt snapshot, og `lat`/`lng` kan klienten sætte selv.
     * Derfor tager metoden adressen, lader `resolveAddressAnalysis()` (som kræver
     * at sessionen har godkendt netop dén adresse) finde koordinaterne, og bruger
     * aldrig `$this->lat`/`$this->lng`. Uden godkendelse kommer der intet ud.
     *
     * Ejernavne og gæld er med vilje ikke med: registry-api leverer dem ikke i
     * udforsk-svaret, og gæld hører til bestilte analyser (som i Udforsk).
     *
     * @return array{error: string}|array{naboer: list<array<string, mixed>>, flere: bool, radius: int, sorteret: bool}
     */
    #[Renderless]
    public function hentNaboer(string $query, int $radius = self::NABO_STANDARD_RADIUS): array
    {
        // 🚨 Naboernes vurderinger er data om ANDRE ejendomme end den godkendte adresse; en anonym
        // gratis-prøve (ét opslag) må ikke kunne hente dem igen og igen. Samme regel som kvote-fritagelsen
        // (verificeret mail eller pilot).
        if (! app(LookupAccess::class)->erKvoteFritaget()) {
            return ['error' => 'kraever_login'];
        }

        $radius = in_array($radius, self::NABO_RADIER, true) ? $radius : self::NABO_STANDARD_RADIUS;
        $api = app(RegistryApi::class);

        // Kvotefejl vises som kvotefejl; alle andre undtagelser får lov at rapportere.
        try {
            $analysis = $api->resolveAddressAnalysis($query);
        } catch (QuotaExceededException) {
            return ['error' => 'kvote'];
        }
        if (isset($analysis['error'])) {
            return ['error' => ($analysis['status'] ?? null) === 403 ? 'ikke_godkendt' : 'fejl'];
        }

        $coords = $analysis['property']['coordinates'] ?? null;
        $lat = (float) ($coords['lat'] ?? $coords['latitude'] ?? 0);
        $lng = (float) ($coords['lng'] ?? $coords['longitude'] ?? 0);
        if (! $lat || ! $lng) {
            return ['error' => 'ingen_koordinater'];
        }

        // Cachen læses FØRST nu, bag godkendelsen, så den ikke kan springe gaten over.
        $cacheNoegle = 'metis:naboer:v2:'.md5(mb_strtolower(trim($query))).':'.$radius;
        if (is_array($cached = Cache::get($cacheNoegle))) {
            return $cached;
        }

        // Taelleren bruges kun, naar der faktisk skal et kald til registry-api.
        $noegle = 'metis-naboer:'.(app(LookupAccess::class)->klientIp() ?? request()->ip());
        if (! RateLimiter::attempt($noegle, 20, fn () => true, 60)) {
            return ['error' => 'for_mange'];
        }

        $vilkaar = ['lat' => $lat, 'lng' => $lng, 'radius' => $radius, 'limit' => self::NABO_MAKS];
        $sorteret = true;
        try {
            $svar = $api->propertyExplore($vilkaar + ['sort' => 'distance_asc'], source: 'map_neighbours');
            // Et ældre registry-api kender ikke distance_asc og svarer 422. Så falder vi tilbage til
            // standardsorteringen i stedet for at slå hele funktionen fra, mens de to deployes.
            if (($svar['status'] ?? null) === 422) {
                $sorteret = false; // så kortet ikke påstår "de nærmeste" om et usorteret udsnit
                $svar = $api->propertyExplore($vilkaar, source: 'map_neighbours');
            }
        } catch (QuotaExceededException) {
            return ['error' => 'kvote'];
        }

        if (isset($svar['error'])) {
            // 429 fra registry-api er dets throttle pr. bruger, ikke Metis' dagskvote.
            return ['error' => ($svar['status'] ?? null) === 429 ? 'for_mange' : 'fejl'];
        }

        // Selve ejendommen har allerede sin markør. Den kendes på matrikel_id fra analysen; findes
        // det ikke, sammenlignes adressen. Aldrig koordinater: andre lejligheder i samme bygning
        // deler koordinater og skal blive. 🪤 parseAddress() kan ikke dele alle adresser
        // (husbogstav, etage), så adressematchet er kun en reserve.
        $selvId = $analysis['property']['matrikel_id'] ?? null;
        $p = $api->parseAddress($query);
        $selv = mb_strtolower(trim(($p['street'] ?? '').' '.($p['number'] ?? '')));

        $naboer = [];
        foreach ($svar['data']['results'] ?? [] as $r) {
            $rLat = $r['latitude'] ?? null;
            $rLng = $r['longitude'] ?? null;
            if ($rLat === null || $rLng === null) {
                continue;
            }
            if ($selvId !== null && (string) ($r['matrikel_id'] ?? '') === (string) $selvId) {
                continue;
            }
            if ($selvId === null && $selv !== '' && mb_strtolower(trim((string) ($r['address'] ?? ''))) === $selv) {
                continue;
            }
            $naboer[] = $this->somKortRaekke($r, (float) $rLat, (float) $rLng);
        }

        $resultat = [
            'naboer' => $naboer,
            // Afstandssorteringen har ingen cursor og melder derfor `truncated`, ikke `has_more`.
            'flere' => (bool) (($svar['data']['has_more'] ?? false) || ($svar['data']['truncated'] ?? false)),
            'radius' => $radius,
            'sorteret' => $sorteret,
        ];
        Cache::put($cacheNoegle, $resultat, now()->addMinutes(5));

        return $resultat;
    }

    /**
     * Hvidliste: intet andet end disse felter forlader serveren. Linket bygges af MetisLink
     * (samme guards som alle andre opslagslinks); null, hvis adressen ikke kan slås op, og så
     * vises ingen "Åbn"-knap.
     *
     * @param  array<string, mixed>  $r
     * @return array<string, mixed>
     */
    protected function somKortRaekke(array $r, float $lat, float $lng): array
    {
        $adresseTekst = trim(($r['address'] ?? '').', '.trim(($r['postal_code'] ?? '').' '.($r['city'] ?? '')), ' ,');

        return [
            'adresse' => $r['address'] ?? null,
            'postnr' => $r['postal_code'] ?? null,
            'by' => $r['city'] ?? null,
            'byggeaar' => $r['year_built'] ?? null,
            'areal' => $r['area_building'] ?? null,
            'vurdering' => $r['valuation'] ?? null,
            'url' => ($r['address'] ?? null) ? MetisLink::urlFor('address', $adresseTekst) : null,
            'lat' => $lat,
            'lng' => $lng,
        ];
    }

    /**
     * Ejendomme i det udsnit af kortet brugeren kigger på (panorér og zoom frit).
     *
     * 🚨 Kun for piloter (`harPilotAdgang()`), og tjekket sker HER, hver gang: `kanUdforske`
     * er en offentlig egenskab, så klienten kan sætte den selv. En anonym gratis-prøve må ikke
     * kunne gennemløbe landet ejendom for ejendom. Udsnittet har loft på udstrækning og antal,
     * snappes til et gitter (cache 5 min, så små panoreringer er gratis) og har egen grænse.
     *
     * @return array{error: string}|array{naboer: list<array<string, mixed>>, flere: bool}
     */
    #[Renderless]
    public function hentUdsnit(mixed $syd, mixed $vest, mixed $nord, mixed $oest): array
    {
        if (! app(LookupAccess::class)->harPilotAdgang()) {
            return ['error' => 'kraever_pilot'];
        }

        // Ingen typede parametre: et ikke-tal fra klienten ville ellers give en TypeError (500) i stedet
        // for 'ugyldigt', og ville ramme før adgangstjekket ovenfor.
        foreach ([$syd, $vest, $nord, $oest] as $v) {
            if (! is_numeric($v) || ! is_finite((float) $v)) {
                return ['error' => 'ugyldigt'];
            }
        }
        [$syd, $vest, $nord, $oest] = [(float) $syd, (float) $vest, (float) $nord, (float) $oest];
        // Danmark med god margen. Alt andet er ikke et gyldigt udsnit.
        if ($syd < 54.4 || $nord > 57.9 || $vest < 7.8 || $oest > 15.3 || $syd >= $nord || $vest >= $oest) {
            return ['error' => 'ugyldigt'];
        }

        $g = self::UDSNIT_GITTER;
        // round() fjerner flydende-tals-støj (12.572 / 0.002 giver 6285.999999...), så en værdi
        // præcis på en gitterlinje ikke havner i den forkerte celle og giver en anden cache-nøgle.
        $syd = floor(round($syd / $g, 6)) * $g;
        $vest = floor(round($vest / $g, 6)) * $g;
        $nord = ceil(round($nord / $g, 6)) * $g;
        $oest = ceil(round($oest / $g, 6)) * $g;

        // Tolerance: 20 x 0,002 er 0,04000000000000001 i flydende tal.
        if (($nord - $syd) > self::UDSNIT_MAKS_BREDDE_LAT + 1e-9 || ($oest - $vest) > self::UDSNIT_MAKS_BREDDE_LNG + 1e-9) {
            return ['error' => 'zoom_ind'];
        }

        $noegle = 'metis:udsnit:'.md5(sprintf('%.4f|%.4f|%.4f|%.4f', $syd, $vest, $nord, $oest));
        if (is_array($cached = Cache::get($noegle))) {
            return $cached;
        }

        // Hvert udsnit koster op til UDSNIT_SIDER kald, og registry-api har egen throttle på 60/min
        // pr. bruger; derfor er vores grænse 15 udsnit i minuttet, ikke 60.
        $ratekey = 'metis-udsnit:'.(app(LookupAccess::class)->klientIp() ?? request()->ip());
        if (! RateLimiter::attempt($ratekey, 15, fn () => true, 60)) {
            return ['error' => 'for_mange'];
        }

        $polygon = [
            ['lat' => $syd, 'lng' => $vest],
            ['lat' => $nord, 'lng' => $vest],
            ['lat' => $nord, 'lng' => $oest],
            ['lat' => $syd, 'lng' => $oest],
        ];

        $raekker = [];
        $cursor = null;
        $flere = false;
        for ($side = 0; $side < self::UDSNIT_SIDER; $side++) {
            try {
                $svar = app(RegistryApi::class)->propertyExplore(array_filter([
                    'polygon' => $polygon,
                    'limit' => self::UDSNIT_SIDE,
                    'cursor' => $cursor,
                ]), source: 'map_viewport');
            } catch (QuotaExceededException) {
                return ['error' => 'kvote'];
            }

            if (isset($svar['error'])) {
                // En fejl på side 2 eller 3 giver ikke et halvt udsnit; hele udsnittet melder fejl.
                return ['error' => ($svar['status'] ?? null) === 429 ? 'for_mange' : 'fejl'];
            }

            foreach ($svar['data']['results'] ?? [] as $r) {
                if (($r['latitude'] ?? null) === null || ($r['longitude'] ?? null) === null) {
                    continue;
                }
                $raekker[] = $this->somKortRaekke($r, (float) $r['latitude'], (float) $r['longitude']);
            }

            $cursor = $svar['data']['cursor'] ?? null;
            $flere = (bool) ($svar['data']['has_more'] ?? false);
            if (! $flere || $cursor === null) {
                break;
            }
        }

        $resultat = ['naboer' => $raekker, 'flere' => $flere];
        Cache::put($noegle, $resultat, now()->addMinutes(5));

        return $resultat;
    }

    protected function loadLayers(): void
    {
        try {
            $result = app(RegistryApi::class)->getMapLayers();

            if (is_array($result) && isset($result['error'])) {
                $this->layersUnavailable = true;
                return;
            }

            $this->layers = is_array($result) ? $result : [];
        } catch (\Throwable) {
            $this->layersUnavailable = true;
        }
    }

    public function render(): View
    {
        return view('metis::livewire.map-panel');
    }
}
