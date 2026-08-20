<?php

namespace TheFountainhead\Metis\View\Components;

use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;
use TheFountainhead\Metis\Services\SearchDetector;

class MetisLink extends Component
{
    public function __construct(
        public string $type,
        public string $query,
        public ?string $label = null,
    ) {}

    /**
     * URL'en til opslagssiden — eller null hvis den ikke kan bygges.
     *
     * 🚨 GUARDEN ER IKKE TEORETISK. Ruten hedder `metis.lookup` i BEGGE
     * rutefiler, men i embedded mode registreres `routes/embedded.php` kun
     * hvis host-appen selv kalder `MetisServiceProvider::embeddedRoutes()`
     * — og INTET i pakken kalder den. Dertil kan en host's egen
     * `routes/web.php` shadow'e pakkens rute: Laravels RouteCollection
     * keyer paa `method.uri` og overskriver ved kollision, saa NAVNET
     * forsvinder lydloest fra nameList. `route:list` viser kun vinderen.
     *
     * Uden guarden kaster `route()` da RouteNotFoundException, og fordi
     * komponenten bruges 17 steder — midt i tabeller og kort — bliver det
     * til en white-screen paa en kundevendt side, ikke et manglende link.
     * Praecedens: signing-room PR #8 (Gesda, Flare 8664828) og
     * compound_signing_room_url_shadow_2026_05_01, hvor 6 af 6 tenants blev
     * ramt af netop den klasse.
     *
     * 🪤 Testene kan IKKE se det: `tests/TestCase.php:37` loader kun
     * `routes/web.php`. Embedded-stien er helt udaekket, saa en fejl dér
     * ville vaere usynlig for hele suiten.
     *
     * null frem for et fallback-link: en knap der lydloest foerer til
     * forsiden er vaerre end ingen knap. Bladen renderer da den samme
     * inaktive `<span>` som ved tom query.
     */
    /**
     * Samme regler som `url()`, men uden en komponent-instans.
     *
     * 🔑 Til REDIRECTS og andre steder der skal bruge URL'en frem for at
     * rendere et link. Foer denne fandtes, haandrullede fem `redirect()`-kald
     * i `Index`/`Lookup` deres eget `route('metis.lookup', …)` — og arvede
     * dermed ingen af de fire guards her. Migreret 20/8.
     *
     * 🪤 DELER KROP med `url()`. To implementeringer af samme regel driver
     * fra hinanden — kodebasen har allerede betalt for det med FIRE
     * CPR-detektorer, hvor den fjerde accepterede et format de tre andre
     * afviste.
     */
    public static function urlFor(string $type, string $query): ?string
    {
        return (new self($type, $query))->url();
    }

    public function url(): ?string
    {
        // 🚨 TOM QUERY KASTER — `UrlGenerationException: Missing required
        // parameter`. Bladen har en `@if($query && ...)`-guard, men den er
        // ikke redundant med denne: uden dette tjek er bladen det ENESTE der
        // staar mellem et tomt felt og et ukontrolleret throw paa 33
        // kaldesteder. Invarianten hoerer hjemme ét sted — her, hvor URL'en
        // bygges — saa en fremtidig kalder af url() ikke arver faelden.
        // (Review-fund: en mutation der fjernede bladens guard overlevede
        // hele suiten.)
        if (trim($this->query) === '') {
            return null;
        }

        if (! Route::has('metis.lookup')) {
            return null;
        }

        // 🚨 ET CPR MAA ALDRIG I EN URL. Guarden staar HER, hvor URL'en
        // BYGGES — ikke kun i Lookup::mount(), som er modtageren.
        //
        // Lookup fanger allerede et CPR og redirecter (Lookup.php:98), saa
        // `metis_lookups` er beskyttet (verificeret: 0 raekker). Men det sker
        // EFTER at URL'en er dannet og udleveret: CPR'et staar da allerede i
        // href'en, i browserhistorikken og i en eventuel Referer — og
        // standalone-layoutet loader unpkg + Cloudflare paa hver side.
        // Praecedens for at det ikke er teoretisk: Googlebot hentede 35
        // unikke CPR-URL'er 9/8.
        //
        // 🪤 rawurlencode() aendrer IKKE et CPR (ingen tegn at encode), saa
        // encodingen nedenfor er ingen beskyttelse.
        //
        // Maalt 18/8: 0 af 744.134 selskaber har en 10-cifret identifikator,
        // saa triggeren er ikke aktuel i dag. Guarden er billig og lukker
        // hullet uanset hvad EJF sender i morgen — kaldestederne stoler i dag
        // paa `type === 'company'`, altsaa en TYPEPAASTAND fra en ekstern
        // kilde, ikke en formkontrol af selve vaerdien.
        //
        // Genbruger den kanoniske detektor: regexen fandtes engang i FEM
        // kopier med hver sin normalisering (SearchDetector.php:25-31).
        if ((new SearchDetector)->isCpr($this->query) && strtolower($this->type) !== 'cpr') {
            return null;
        }

        return route('metis.lookup', [
            'type' => $this->type,
            'query' => rawurlencode($this->query),
        ]);
    }

    public function render()
    {
        return view('metis::components.metis-link');
    }
}
