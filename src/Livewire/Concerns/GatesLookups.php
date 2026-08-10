<?php

namespace TheFountainhead\Metis\Livewire\Concerns;

/**
 * Kvote-gaten for opslag — ÉT sted, brugt af begge indgange.
 *
 * 🚨 MAALT PAA PROD 9/8: gaten fandtes kun i `Search::performSearch()`. En
 * bruger der gik direkte til `/lookup/cvr/12345678` ramte den aldrig, fordi
 * `Lookup::mount()` er en anden doer ind i det samme produkt.
 *
 * Verificeret udefra: fem opslag i samme session gav fem gange HTTP 200 med
 * syv datasektioner der loadede. Prod-tal samme dag:
 *
 *   metis_lookups   8.259 raekker
 *   users               0
 *   lookups             0   (kvote-taelleren, migreret 15/3)
 *
 * 8.259 opslag, nul brugere. Produktet blev udleveret gratis og anonymt.
 *
 * 🔑 SAMME FEJLKLASSE SOM NOINDEX SAMME DAG: beskyttelsen var bygget og
 * fungerede — den sad bare kun paa den ene af to veje ind. Derfor ligger
 * logikken nu i en trait frem for at blive skrevet igen: kodebasen har
 * allerede betalt for den fejl med FIRE CPR-detektorer, hvor den fjerde
 * accepterede et format de tre andre afviste.
 *
 * 🪤 Sessionsbaseret, ikke IP-baseret. Det er bevidst: en delt IP (kontor,
 * mobilnet) ville ellers laase hele huset ude efter ét opslag. En bruger der
 * rydder cookies faar nye gratis opslag — det er prisen for ikke at ramme
 * uskyldige, og den samme afvejning `Search` allerede traf.
 */
trait GatesLookups
{
    /**
     * Skal opslaget stoppes af kvote-gaten?
     *
     * 🪤 Raekkefoelgen er den samme som i `Search::performSearch()`: gaten FOER
     * rate limit, og begge sprunget over naar gating er slaaet fra (embedded
     * mode) eller en pilot-token er aktiv. Rasmus-piloten maa ikke rammes af
     * den offentlige betas kvoter.
     */
    protected function skalGates(): bool
    {
        if (! config('metis.gating.enabled', true)) {
            return false;
        }

        if (session('metis_user_token')) {
            return false;
        }

        if (session('metis_verified_email')) {
            return false;
        }

        return session('metis_lookup_count', 0) >= config('metis.gating.free_lookups', 1);
    }
}
