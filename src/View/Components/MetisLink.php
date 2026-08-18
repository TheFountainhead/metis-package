<?php

namespace TheFountainhead\Metis\View\Components;

use Illuminate\Support\Facades\Route;
use Illuminate\View\Component;

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
    public function url(): ?string
    {
        if (! Route::has('metis.lookup')) {
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
