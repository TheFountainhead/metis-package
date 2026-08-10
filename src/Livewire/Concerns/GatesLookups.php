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

        // Pilot-token = fuld adgang, ingen kvote. Rasmus-piloten maa ikke
        // rammes af den offentlige betas graenser.
        if (session('metis_user_token')) {
            return false;
        }

        // 🔑 VERIFICERET EMAIL GIVER EN KVOTE, IKKE FRI ADGANG (Frederik 10/8).
        // Foer denne aendring var verifikation en doer der aabnede helt: har du
        // givet din mail, kunne du soege ubegraenset. Modellen er nu at hver
        // testbruger faar et antal opslag og kan ANMODE om flere — saa Frederik
        // ser hvem der rent faktisk bruger produktet, foer der traeffes
        // beslutning om betaling.
        if ($email = session('metis_verified_email')) {
            return $this->kvoteOpbrugtForEmail($email);
        }

        return session('metis_lookup_count', 0) >= config('metis.gating.free_lookups', 1);
    }

    /**
     * Har den verificerede bruger brugt sin tildelte kvote?
     *
     * 🪤 Taeller paa LEAD'ET, ikke i sessionen. En sessionstaeller nulstilles
     * ved cookie-rydning, og hele pointen er at kvoten foelger PERSONEN saa
     * Frederik kan se det reelle forbrug. `lookup_count` opdateres allerede af
     * `EmailGate` ved hver verifikation og af opslagene selv.
     *
     * 🪤 Ukendt email gates IKKE. Lead'et oprettes af `EmailGate` ved
     * verifikation, saa en session med `metis_verified_email` men uden lead er
     * en inkonsistens (fx ryddet database) — og at gate der ville laase en
     * bruger ude uden vej videre, hvor det sikre svar er at lade dem passere.
     */
    protected function kvoteOpbrugtForEmail(string $email): bool
    {
        // 🪤 Fejler opslaget, lukkes der IGENNEM. At laase alle brugere ude
        // fordi en query fejlede ville goere en hikke til et nedbrud.
        $lead = rescue(
            fn () => \TheFountainhead\Metis\Models\MetisLead::where('email', $email)->first(),
            null,
            false
        );

        if (! $lead) {
            return false;
        }

        return $lead->lookup_count >= $lead->lookup_quota;
    }

    /**
     * Tael et gennemfoert opslag paa BAADE sessionen og lead'et.
     *
     * 🚨 MAALT 10/8: `lookup_count` paa lead'et blev ALDRIG taalt op ved et
     * opslag — kun ved verifikation. En kvote der laeser et felt ingen skriver
     * til, ville aldrig blive opbrugt, og gaten ville se ud til at virke mens
     * den slap alt igennem. Samme fejlklasse som `lookups`-tabellen der laa
     * ubrugt siden marts.
     *
     * 🪤 `increment()` frem for laes-og-gem: to samtidige opslag (sektionerne
     * loader parallelt) ville ellers begge laese samme vaerdi og skrive n+1,
     * saa det ene forsvandt. Databasen skal lave optaellingen.
     *
     * 🪤 Sessionstaelleren beholdes ved siden af: den gater ANONYME brugere,
     * hvor der ikke findes et lead at taelle paa.
     */
    protected function taelOpslag(): void
    {
        session(['metis_lookup_count' => session('metis_lookup_count', 0) + 1]);

        if (! session('metis_lookup_window_start')) {
            session(['metis_lookup_window_start' => now()->timestamp]);
        }

        // 🪤 `rescue()`: en fejlet optaelling maa ikke koste brugeren opslaget.
        // Sessionstaelleren ovenfor er allerede sat, saa gaten virker uanset.
        if ($email = session('metis_verified_email')) {
            rescue(fn () => \TheFountainhead\Metis\Models\MetisLead::where('email', $email)
                ->update([
                    'lookup_count' => \Illuminate\Support\Facades\DB::raw('lookup_count + 1'),
                    'last_active_at' => now(),
                ]), null, false);
        }
    }
}
