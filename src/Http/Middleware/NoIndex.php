<?php

namespace TheFountainhead\Metis\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * `X-Robots-Tag: noindex, nofollow` paa alt der ikke er rent indhold.
 *
 * 🚨 MAALT PAA PROD 9/8: `/lookup/cpr/<personnummer>` svarede 200 til en
 * anonym klient, og Googlebot havde hentet 35 unikke CPR-URL'er (269
 * requests, alle 200). Meta-tagget i standalone-layoutet blev udeladt, fordi
 * dets betingelse laeste `request()->query()` — og CPR'et ligger i STIEN.
 *
 * 🔑 HVORFOR BAADE HEADER OG META-TAG. De daekker hver sit hul:
 *
 *   meta-tag  virker kun i HTML. Livewire-svar er JSON og gaar uden om
 *             layoutet helt — og det er DER dataene ligger. Den initiale
 *             HTML indeholder kun "Henter data"-pladsholdere; indholdet
 *             kommer i POST'en bagefter. Maalt 9/8: 20.549 af Googlebots
 *             requests var POST.
 *   header    virker paa ethvert svar, uanset content-type.
 *
 * Beskyttelsen har nu ramt ved siden af tre gange (host-appens doede layout,
 * `?cvr=`-siden, og stien her). Hver gang laa den ét sted der ikke daekkede
 * det sted der betoed noget. To uafhaengige lag koster lidt duplikering og
 * fjerner den fejlklasse.
 *
 * 🪤 Whitelisten er RUTENAVNE, ikke stier — stier skal holdes synkron med
 * hver URL-aendring; rutenavne foelger med ruten. Samme liste som layoutet.
 *
 * 🪤 Headeren er IKKE adgangskontrol. Den beder soegemaskiner om ikke at
 * indeksere; den forhindrer ingen i at hente siden. Ruten er fortsat aaben
 * for enhver klient — se `project_metis_lookup_cpr_exposure_2026_08_09`.
 */
class NoIndex
{
    /**
     * Sider der maa indekseres. Alt andet faar noindex.
     *
     * @var array<int, string>
     */
    private const INDEKSERBARE_RUTER = [
        'metis.home',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($this->maaIndekseres($request)) {
            return $response;
        }

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }

    private function maaIndekseres(Request $request): bool
    {
        // 🪤 En query-streng betyder altid "der vises et opslag" — ogsaa paa
        // forsiden, hvor `?q=` baerer soegningen.
        if ($request->query() !== []) {
            return false;
        }

        return in_array($request->route()?->getName(), self::INDEKSERBARE_RUTER, true);
    }
}
