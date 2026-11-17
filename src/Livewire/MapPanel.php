<?php

namespace TheFountainhead\Metis\Livewire;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Attributes\On;
use Livewire\Component;
use TheFountainhead\Metis\Services\LookupAccess;
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

    /** Radier (meter) brugeren kan vælge til naboejendomme. */
    public const NABO_RADIER = [100, 250, 500];

    /** Så mange ejendomme hentes højst pr. opslag (registry-apis loft er 50). */
    public const NABO_MAKS = 50;

    public function mount(?float $lat = null, ?float $lng = null, ?string $searchType = null, ?string $query = null): void
    {
        $this->lat = $lat;
        $this->lng = $lng;
        $this->searchType = $searchType;
        $this->query = $query;

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
     * @return array{error: string}|array{naboer: list<array<string, mixed>>, flere: bool, radius: int}
     */
    public function hentNaboer(string $query, int $radius = 250): array
    {
        $radius = in_array($radius, self::NABO_RADIER, true) ? $radius : 250;

        $noegle = 'metis-naboer:'.(app(LookupAccess::class)->klientIp() ?? request()->ip());
        if (! RateLimiter::attempt($noegle, 20, fn () => true, 60)) {
            return ['error' => 'for_mange'];
        }

        $analysis = rescue(fn () => app(RegistryApi::class)->resolveAddressAnalysis($query), ['error' => 'fejl'], false);
        if (isset($analysis['error'])) {
            return ['error' => ($analysis['status'] ?? null) === 403 ? 'ikke_godkendt' : 'fejl'];
        }

        $coords = $analysis['property']['coordinates'] ?? null;
        $lat = (float) ($coords['lat'] ?? $coords['latitude'] ?? 0);
        $lng = (float) ($coords['lng'] ?? $coords['longitude'] ?? 0);
        if (! $lat || ! $lng) {
            return ['error' => 'ingen_koordinater'];
        }

        $svar = rescue(fn () => app(RegistryApi::class)->propertyExplore([
            'lat' => $lat,
            'lng' => $lng,
            'radius' => $radius,
            'limit' => self::NABO_MAKS,
        ], source: 'map_neighbours'), ['error' => 'fejl'], false);

        if (isset($svar['error'])) {
            return ['error' => ($svar['status'] ?? null) === 429 ? 'kvote' : 'fejl'];
        }

        $naboer = [];
        foreach ($svar['data']['results'] ?? [] as $r) {
            $rLat = $r['latitude'] ?? null;
            $rLng = $r['longitude'] ?? null;
            if ($rLat === null || $rLng === null) {
                continue;
            }
            // Selve ejendommen har allerede sin egen markør.
            if (abs($rLat - $lat) < 0.00005 && abs($rLng - $lng) < 0.00005) {
                continue;
            }
            // Linket bygges af MetisLink (samme guards som alle andre opslagslinks);
            // null, hvis adressen ikke kan slås op, og så vises ingen "Åbn"-knap.
            $adresseTekst = trim(($r['address'] ?? '').', '.trim(($r['postal_code'] ?? '').' '.($r['city'] ?? '')), ' ,');
            $url = ($r['address'] ?? null) ? MetisLink::urlFor('address', $adresseTekst) : null;

            // Hvidliste: intet andet end disse felter forlader serveren.
            $naboer[] = [
                'adresse' => $r['address'] ?? null,
                'postnr' => $r['postal_code'] ?? null,
                'by' => $r['city'] ?? null,
                'byggeaar' => $r['year_built'] ?? null,
                'areal' => $r['area_building'] ?? null,
                'vurdering' => $r['valuation'] ?? null,
                'url' => $url,
                'lat' => (float) $rLat,
                'lng' => (float) $rLng,
            ];
        }

        return [
            'naboer' => $naboer,
            'flere' => (bool) ($svar['data']['has_more'] ?? false),
            'radius' => $radius,
        ];
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
