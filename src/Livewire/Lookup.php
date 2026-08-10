<?php

namespace TheFountainhead\Metis\Livewire;

use Livewire\Attributes\On;
use Livewire\Component;
use TheFountainhead\Metis\Livewire\Concerns\GatesLookups;
use TheFountainhead\Metis\Models\MetisLookup;
use TheFountainhead\Metis\Services\SearchDetector;

class Lookup extends Component
{
    use GatesLookups;

    public string $type;

    public string $query;

    /**
     * Er dette opslag stoppet af kvote-gaten?
     *
     * 🚨 MAALT PAA PROD 9/8: gaten fandtes KUN i `Search::performSearch()`.
     * `/lookup/{type}/{query}` er en anden doer ind i samme produkt, og den
     * havde ingen. Verificeret udefra: fem opslag i samme session gav fem
     * gange HTTP 200 med syv datasektioner der loadede.
     *
     * Prod samme dag: 8.259 raekker i `metis_lookups`, 0 brugere, 0 raekker i
     * kvote-taelleren. Produktet blev udleveret gratis og anonymt.
     */
    public bool $gated = false;

    /**
     * Et 10-cifret tal paa /lookup/cvr/ er et CPR, ikke et CVR.
     *
     * 🚨 MAALT 5/8 (Flare #9104992, n=2 -> 14 paa seks doegn, prod-trafik):
     * brugere rammer /lookup/cvr/ med et CPR. Konsekvensen var toledet:
     *
     *   1. lookup.blade.php:24 loader OTTE selskabssektioner for type=cvr.
     *      Alle otte kalder API'et med den ugyldige vaerdi, alle faar 422, og
     *      ingen validerer foerst. Én fejlindtastning blev til en byge af
     *      uhaandterede exceptions — derfor 14 og ikke 1.
     *   2. Brugeren saa en fejlside, selvom vi HAR en CPR-side. Vi sendte dem
     *      bare ikke derhen.
     *
     * 🪤 Og et CPR blev gemt i `metis_lookups.search_term` (maalt: 4 raekker
     * under search_type='cvr'). Flare censurerer i UI'et, men vores egen
     * historik-tabel gjorde ikke. Derfor redirectes FOER MetisLookup::create().
     */
    public function mount(string $type, string $query): void
    {
        // 🚨 REVIEW-FUND 5/8: foerste udkast skrev sin EGEN regex `^\d{10}$`.
        // Kodebasen har allerede TRE CPR-detektorer, og alle tre accepterer
        // bindestreg: SearchDetector:26, Search:127 og Search:263 bruger
        // `^\d{6}-?\d{4}$`.
        //
        // Danske CPR skrives konventionelt MED bindestreg (DDMMYY-XXXX), saa
        // den mest almindelige form slap forbi. Maalt gennem en RIGTIG
        // HTTP-request, ikke kun Livewire::test():
        //
        //   /lookup/cvr/1234567890   -> 302, ingen historik-raekke   ✅
        //   /lookup/cvr/123456-7890  -> 500, CPR GEMT i historikken  🚨
        //
        // PR'ens egen overskrift — "et CPR lander ikke laengere i
        // metis_lookups" — var altsaa kun sand for én af tre former.
        //
        // 🔑 Og det er praecis den fejlklasse compounden om soege-modes
        // beskriver: jeg byggede en FJERDE detektor i stedet for at bruge den
        // der fandtes. Derfor delegeres nu til SearchDetector.
        //
        // 🪤 Tildel FOER guarden. Foerste udkast returnerede foer $type/$query
        // blev sat, saa enhver sti der naaede render() ramte en
        // uinitialiseret typed property — det var den 500 ovenfor.
        $this->type = $type;
        $this->query = $query;

        // 🚨 REVIEW-FUND 5/8: guarden var utilstraekkelig paa TRE maader.
        // Maalt gennem rigtige HTTP-requests:
        //
        //   /lookup/person/123456-7890  -> CPR GEMT (search_type=person)  🚨
        //   /lookup/address/123456-7890 -> CPR GEMT                       🚨
        //   /lookup/name/123456-7890    -> CPR GEMT                       🚨
        //   /lookup/CVR/123456-7890     -> CPR GEMT (case-sensitiv guard) 🚨
        //   /lookup/cvr/123456 7890     -> redirect, men CPR gemt paa
        //                                  CPR-SIDEN bagefter             🚨
        //
        // Kun `cvr`-doeren var lukket, og kun i smaa bogstaver. `{type}` har
        // ingen rute-begraensning, saa enhver vaerdi naar mount().
        //
        // Rettelsen har to dele:
        //   1. CPR-tjekket gaelder ALLE typer, case-insensitivt.
        //   2. Et CPR skrives ALDRIG til historikken — heller ikke paa
        //      CPR-siden selv, hvor det foer faldt lige igennem til
        //      MetisLookup::create(). Flare censurerer i sit UI; vores egen
        //      tabel gjorde ikke.
        $erCpr = (new SearchDetector)->isCpr($query);

        if ($erCpr && strtolower($type) !== 'cpr') {
            $this->redirect(route('metis.lookup', ['type' => 'cpr', 'query' => $query]), navigate: true);

            return;
        }

        // 🚨 KVOTE-GATEN. Maalt paa prod 9/8: den fandtes KUN i
        // `Search::performSearch()`, saa et direkte kald til
        // `/lookup/cvr/12345678` omgik den fuldstaendigt.
        //
        // 🪤 EFTER CPR-redirecten, FOER historik-skrivningen. Rammes gaten,
        // skal opslaget hverken taelles eller logges — ellers ville et blokeret
        // opslag bruge af brugerens egen kvote, og `metis_lookups` ville
        // registrere et opslag der aldrig blev vist.
        //
        // 🔑 Logikken deles med `Search` via `GatesLookups` frem for at blive
        // skrevet igen. Kodebasen har allerede betalt for den fejl med FIRE
        // CPR-detektorer, hvor den fjerde accepterede et format de tre andre
        // afviste.
        if ($this->skalGates()) {
            $this->gated = true;
            $this->dispatch('show-email-gate');

            return;
        }

        // Save to history — in embedded mode use auth user, in standalone mode use session
        $data = [
            'search_type' => $type,
            'search_term' => $query,
            'ip_address' => request()->ip(),
            'is_cross_reference' => false,
        ];

        if (config('metis.mode') === 'embedded' && auth()->check()) {
            $data['email'] = auth()->user()->email;
        } else {
            $data['session_id'] = session()->getId();
            $data['email'] = session('metis_verified_email');
        }

        // 🚨 Et CPR gemmes ALDRIG. Foer faldt CPR-siden lige igennem hertil,
        // saa redirecten flyttede bare nummeret fra én raekke til en anden.
        if (! $erCpr) {
            rescue(fn () => MetisLookup::create($data));
        }

        // 🚨 TAEL OGSAA CPR-OPSLAG. Logning og kvote er to forskellige
        // spoergsmaal: nummeret maa ikke gemmes, men opslaget skal koste. Uden
        // denne linje uden for `if (! $erCpr)` ville netop CPR-ruten — den der
        // baerer persondata — vaere gratis og ubegraenset.
        //
        // 🪤 Samme taeller som `Search`, via traiten. To implementeringer ville
        // drive fra hinanden, og gaten laeser kun den ene — praecis den fejl
        // review-runden 9/8 fandt i selve gate-logikken.
        $this->taelOpslag();
    }

    /**
     * 🚨 REVIEW-FUND 9/8: uden denne lytter var gaten en BLINDGYDE.
     *
     * `EmailGate` udsender `email-verified` (EmailGate.php:186), og `Search`
     * lytter (Search.php:470). `Lookup` gjorde ikke. En bruger paa
     * `/lookup/cvr/123` ramte gaten, indtastede sin mail, verificerede — og
     * blev siddende paa "Du har brugt dine gratis opslag" uden vej videre.
     * De havde gjort praecis hvad vi bad om, og intet skete.
     *
     * 🪤 Testen der bestod, saaede `metis_verified_email` i sessionen FOER
     * requestet og sprang dermed selve overgangen over — den beviste at en
     * allerede-verificeret bruger slipper ind, ikke at man KAN blive det.
     */
    #[On('email-verified')]
    public function onEmailVerified(string $email): void
    {
        session(['metis_verified_email' => $email]);

        // Genindlaes ruten, saa sektionerne mountes paa ny med kvoten aabnet.
        // Uden redirect ville `$gated = false` alene ikke hjaelpe: sektionerne
        // er `lazy` og blev aldrig mountet i den gatede render.
        $this->redirect(
            route('metis.lookup', ['type' => $this->type, 'query' => $this->query]),
            navigate: true
        );
    }

    public function render()
    {
        $view = view('metis::livewire.lookup');

        if (config('metis.mode') === 'standalone') {
            return $view->layout('metis::layouts.standalone');
        }

        return $view;
    }
}
