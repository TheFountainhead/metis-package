<?php

namespace TheFountainhead\Metis\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * ÉN definition af hvem der maa se hvad i Metis' offentlige opslag.
 *
 * Frederiks beslutning 6/10-2026:
 *
 *   1. Person- og CPR-opslag kraever en IDENTIFICERET bruger.
 *   2. Adresse- og CVR-opslag forbliver en gratis proeve, men kvoten skal
 *      vaere holdbar: bundet til klientens IP OG sessionen, saa en ny cookie
 *      ikke giver et nyt opslag.
 *
 * 🚨 HVORFOR EN KLASSE OG IKKE TRE KOPIER. Foer denne aendring stod kvote-
 * taersklen tre steder: `GatesLookups` (`>= 1`), `MetisSection` (`> 1`) og
 * `RegistryApi` (`> 1`). Forskellen var ikke tilfaeldig, men den var
 * implicit, og den ene kopi havde allerede glemt `metis_verified_email` paa
 * en anden maade end de andre. Her staar reglen ét sted:
 *
 *     opslag nr. N er tilladt  <=>  N <= free_lookups
 *
 * Siden spoerger om det opslag den er ved at STARTE (N = brugt + 1). Data-
 * laget spoerger om det opslag sessionen allerede har faaet (N = brugt). Det
 * er det samme praedikat, anvendt paa to forskellige opslag, ikke to regler.
 *
 * 🔑 "IDENTIFICERET" = `metis_verified_email` paa sessionen. Den saettes KUN
 * paa serveren: af `EmailGate::verifyCode()` efter en korrekt kode
 * (tilmelding = navn + arbejdsmail) og af `PilotLogin::attach()` efter
 * kodeord eller husk-mig-cookie. Et `metis_user_token` ALENE taeller ikke:
 * `AlertsInbox::setToken()` lader enhver indtaste en streng i det format, og
 * cachede svar ville da blive udleveret uden at tokenet nogensinde blev
 * proevet mod registry-api.
 */
class LookupAccess
{
    /**
     * Cloudflares egde-netvaerk (https://www.cloudflare.com/ips-v4 og -v6,
     * hentet 6/10-2026). Kun naar requesten kommer FRA et af dem, stoler vi
     * paa `CF-Connecting-IP`; ellers kunne enhver der rammer origin direkte
     * skrive sin egen IP i headeren og faa et nyt gratis opslag pr. request.
     */
    public const CLOUDFLARE_PROXIES = [
        '173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22',
        '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20',
        '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13',
        '104.24.0.0/14', '172.64.0.0/13', '131.0.72.0/22',
        '2400:cb00::/32', '2606:4700::/32', '2803:f800::/32', '2405:b500::/32',
        '2405:8100::/32', '2a06:98c0::/29', '2c0f:f248::/32',
    ];

    public function gatingAktiv(): bool
    {
        return (bool) config('metis.gating.enabled', true);
    }

    /**
     * Koerer vi i en request med en startet session?
     *
     * 🚨 OPFOELGNING (review M4): UDEN session er kalderen ANONYM MED
     * PROEVEN BRUGT. Foer var det omvendt: `erGodkendt()`,
     * `anonymKvoteOverskredet()` og `RegistryApi::loginKraevetFejl()` sagde
     * "tilladt" uden session, med begrundelsen at baggrundsjob ikke skulle
     * rammes. Maalt 6/10: hverken pakken eller vaerten (metis) kalder
     * `RegistryApi` fra et job eller en kommando, saa undtagelsen beskyttede
     * intet. Den aabnede derimod alt for en fremtidig stateless rute, og for
     * `Livewire::test()` uden session (som reviewerens foerste test ramte).
     *
     * 🪤 Skal et job en dag hente data, saa slaa gating fra for det kald
     * (`metis.gating.enabled`) frem for at genindfoere en undtagelse her.
     * En undtagelse "uden session" er praecis den bypass M4 lukker.
     *
     * Alle web-indgange (siden og `/livewire/update`) ligger i `web`-gruppen,
     * som altid starter sessionen.
     */
    public function harSession(): bool
    {
        return app()->bound('session') && session()->isStarted();
    }

    /** Maa denne bruger se person- og CPR-data? */
    public function erIdentificeret(): bool
    {
        if (! $this->gatingAktiv()) {
            return true;
        }

        // 🚨 M4: vaerdier i en IKKE-startet session kan ikke komme fra en
        // cookie og taeller ikke.
        return $this->harSession() && filled(session('metis_verified_email'));
    }

    /**
     * Er dette en bekraeftet pilot?
     *
     * 🚨 FIX-RUNDE 1 (review V2): et `metis_user_token` ALENE er ikke en
     * pilot. `AlertsInbox::setToken()` accepterer enhver streng paa formen
     * `<tal>|<tegn>`, og et falsk token fritog for kvoten og IP-taellingen og
     * gav adgang til cachede ejere og pantebreve (review POC B). Kun to veje
     * er bekraeftet paa serveren:
     *
     *   - `PilotLogin::attach()` (kodeord eller husk-mig) saetter
     *     `metis_pilot_account_id`;
     *   - `EmailGate::verifyCode()` saetter `metis_pilot_verificeret`, naar
     *     den verificerede mail staar i `metis.gating.pilot_users`.
     */
    public function erPilot(): bool
    {
        return $this->harSession()
            && filled(session('metis_user_token'))
            && (filled(session('metis_pilot_account_id')) || session('metis_pilot_verificeret') === true);
    }

    /**
     * Er brugeren fritaget fra den ANONYMES gratis-kvote?
     *
     * Bekraeftet pilot eller verificeret mail. Verificerede brugere har deres
     * egen lead-kvote, som `GatesLookups` haandhaever.
     */
    public function erKvoteFritaget(): bool
    {
        if (! $this->gatingAktiv()) {
            return true;
        }

        return $this->erPilot() || $this->erIdentificeret();
    }

    public function graense(): int
    {
        return (int) config('metis.gating.free_lookups', 1);
    }

    /**
     * Hoejst saa mange anonyme opslag pr. IP pr. vindue (Frederik 6/10: 5).
     *
     * 🪤 Ikke 1: mobilnet koerer CGNAT, hvor mange kunder deler én IP. Med 1
     * ville den foerste paa masten bruge proeven for alle de andre.
     */
    public function ipGraense(): int
    {
        // 🔑 Standarden (5) staar i config/metis.php, ikke her. En manglende
        // noegle giver 0, altsaa ingen anonym proeve: fail-closed. Vaertens
        // publicerede config mister ikke noeglen, fordi provideren fletter
        // `gating` paa noegleniveau (`MetisServiceProvider::register()`).
        return (int) config('metis.gating.ip_daily_limit');
    }

    /** DEN ENE taerskel pr. session. Se klassens docblock. */
    public function opslagNrErTilladt(int $nr): bool
    {
        return $nr <= $this->graense();
    }

    public function brugtISession(): int
    {
        return (int) session('metis_lookup_count', 0);
    }

    public function brugtFraIp(): int
    {
        $noegle = $this->ipNoegle();

        if ($noegle === null) {
            return 0;
        }

        // 🚨 Fail-closed (opfoelgning 2): kan taelleren ikke laeses, er IP'en
        // brugt op. Foer gav en cache-fejl her 500 paa hele siden.
        try {
            return (int) Cache::get($noegle, 0);
        } catch (\Throwable $e) {
            $this->logTaellerFejl($e, 'laes');

            return $this->ipGraense();
        }
    }

    /**
     * Siden: maa en anonym besoegende STARTE et nyt opslag?
     *
     * Begge graenser skal holde: 1 pr. session OG `ip_daily_limit` pr. IP.
     * Ren laesning til visning; selve reservationen er atomisk i
     * `godkendOpslag()`.
     */
    public function anonymtOpslagTilladt(): bool
    {
        return $this->harSession()
            && $this->opslagNrErTilladt($this->brugtISession() + 1)
            && $this->brugtFraIp() < $this->ipGraense();
    }

    /**
     * Datalaget: har denne session brugt flere opslag end den har faaet?
     *
     * 🪤 KUN sessionen her. IP-graensen haandhaeves atomisk dér hvor et
     * opslag GODKENDES (`godkendOpslag()`), og datalaget kraever desuden at
     * opslaget staar paa sessionens godkendelsesliste (`erGodkendt()`).
     */
    public function anonymKvoteOverskredet(): bool
    {
        if (! $this->gatingAktiv()) {
            return false;
        }

        // 🚨 M4: uden session er proeven brugt.
        if (! $this->harSession()) {
            return true;
        }

        if ($this->erKvoteFritaget()) {
            return false;
        }

        return ! $this->opslagNrErTilladt($this->brugtISession());
    }

    /**
     * Godkend et opslag: reserver en IP-plads ATOMISK og skriv opslaget paa
     * sessionens godkendelsesliste. Returnerer false, hvis IP'en er brugt op.
     *
     * 🚨 FIX-RUNDE 1 (review M1): foer blev IP'en LAEST i gaten og TALT efter
     * render. N samtidige requests fra nye sessioner laeste alle 0 og kom
     * alle igennem. Nu er reservationen `increment` med den RETURNEREDE
     * vaerdi som afgoerelse; overskrides graensen, gives pladsen tilbage.
     *
     * 🚨 FIX-RUNDE 1 (review V1): godkendelseslisten binder sektionerne til
     * de opslag siden faktisk har godkendt i DENNE session. Livewires
     * checksum er bundet til APP_KEY, ikke til sessionen, saa et snapshot fra
     * ét opslag kunne genbruges i en frisk session til et andet (eller
     * samme) CVR. Sektionerne og `resolveAddressAnalysis()` spoerger nu
     * `erGodkendt()` foer de henter.
     *
     * Fritagne brugere (verificeret mail, bekraeftet pilot) og gating slaaet
     * fra springes over: de har ingen anonym kvote.
     */
    public function godkendOpslag(string $type, string $query): bool
    {
        if ($this->erKvoteFritaget()) {
            return true;
        }

        // 🚨 M4: uden session er der ingen godkendelsesliste at skrive paa, og
        // proeven er brugt.
        if (! $this->harSession()) {
            return false;
        }

        $noegle = $this->ipNoegle();

        if ($noegle !== null && ! $this->reserverIpPlads($noegle)) {
            return false;
        }

        $liste = (array) session('metis_godkendte_opslag', []);
        $liste[] = $this->opslagsNoegle($type, $query);
        session(['metis_godkendte_opslag' => array_slice(array_values(array_unique($liste)), -50)]);

        return true;
    }

    /**
     * Reserver én plads paa IP'ens taeller. Sand hvis pladsen blev givet.
     *
     * 🚨 FAIL-CLOSED (opfoelgning 2). Foer stod her `rescue(..., false,
     * false)`: kastede taelleren, blev opslaget GODKENDT, og eneste graense
     * var 1 pr. session, som en ny cookie nulstiller. Nu er en fejl et
     * afslag, og den logges.
     *
     * 🚨 ATOMISK PAA ENHVER STORE (fix-runde 1 paa #193, review F1). Prod
     * koerer `CACHE_STORE=file`, og `FileStore::increment()` er laes-laeg-til-
     * skriv UDEN laas (kun `add()` laaser). Maalt af revieweren: 57 af 60
     * parallelle reservationer godkendt med graensen 5. Afgoerelsen (laes,
     * sammenlign, tael op) sker derfor inde i en cache-laas, som alle stores
     * med `LockProvider` har (FileStore via `add()`, database, redis, array).
     * Kan laasen ikke faas inden for `block()`, er det et afslag (fail-closed)
     * og logges.
     *
     * Offentlig, saa samtidighedstesten kan koere PRAECIS denne kode i
     * separate PHP-processer (`tests/Feature/Fixtures/ip-reservation-worker.php`).
     */
    public function reserverIpPlads(string $noegle): bool
    {
        try {
            return Cache::lock($noegle.':laas', 5)
                ->betweenBlockedAttemptsSleepFor(20)
                ->block(2, function () use ($noegle) {
                    Cache::add($noegle, 0, now()->addHours((int) config('metis.gating.ip_window_hours', 24)));

                    if ((int) Cache::get($noegle, 0) >= $this->ipGraense()) {
                        return false;
                    }

                    Cache::increment($noegle);

                    return true;
                });
        } catch (\Illuminate\Contracts\Cache\LockTimeoutException $e) {
            $this->logTaellerFejl($e, 'laas');

            return false;
        } catch (\Throwable $e) {
            $this->logTaellerFejl($e, 'reserver');

            return false;
        }
    }

    /**
     * Maa denne session hente data for opslaget?
     *
     * Sandt for fritagne brugere og med gating slaaet fra. Uden session
     * falsk (M4). Ellers kun hvis `godkendOpslag()` har godkendt praecis
     * dette opslag i denne session.
     */
    public function erGodkendt(string $type, string $query): bool
    {
        if (! $this->gatingAktiv()) {
            return true;
        }

        // 🚨 M4: uden session er intet godkendt.
        if (! $this->harSession()) {
            return false;
        }

        if ($this->erKvoteFritaget()) {
            return true;
        }

        return in_array($this->opslagsNoegle($type, $query), (array) session('metis_godkendte_opslag', []), true);
    }

    protected function logTaellerFejl(\Throwable $e, string $trin): void
    {
        rescue(fn () => Log::error('metis.ip_taeller_fejlede: anonymt opslag afvist', [
            'trin' => $trin,
            'exception' => $e::class,
            'besked' => $e->getMessage(),
        ]), null, false);
    }

    protected function opslagsNoegle(string $type, string $query): string
    {
        return sha1(strtolower($type).'|'.trim($query));
    }

    /**
     * Klientens rigtige IP.
     *
     * 🔑 Maalt paa prod 6/10: nginx paa serveren har `set_real_ip_from` for
     * alle Cloudflare-net og `real_ip_header X-Forwarded-For`, saa
     * `REMOTE_ADDR` (og dermed `request()->ip()`) ER allerede klientens IP.
     * 0 af 27 `metis_lookups.ip_address` de seneste 14 dage laa i et
     * Cloudflare-net. Appen har ingen TrustProxies-konfiguration.
     *
     * 🪤 Men pakken maa ikke forudsaette den nginx-linje. Uden den ville
     * `request()->ip()` vaere en Cloudflare-edge, og ALLE besoegende bag den
     * edge ville dele én gratis kvote. Kommer requesten fra et Cloudflare-net,
     * bruges derfor `CF-Connecting-IP`, som Cloudflare selv saetter og
     * overskriver. Fra alle andre afsendere ignoreres headeren.
     */
    public function klientIp(): ?string
    {
        $request = request();
        $ip = $request->ip();
        $cf = $request->header('CF-Connecting-IP');

        if ($ip && $cf && filter_var($cf, FILTER_VALIDATE_IP)
            && IpUtils::checkIp($ip, config('metis.gating.cloudflare_proxies', self::CLOUDFLARE_PROXIES))) {
            $ip = $cf;
        }

        return $ip ?: null;
    }

    /**
     * Cache-noeglen for IP'ens taeller.
     *
     * 🪤 IPv6 bindes paa /56. En IPv6-forbindelse faar et helt net og kan
     * skifte adresse inden for det efter forgodtbefindende; en noegle pr. fuld
     * adresse ville vaere et nyt gratis opslag pr. adresse.
     *
     * sha1, saa en raa IP ikke staar i cache-tabellen.
     */
    protected function ipNoegle(): ?string
    {
        $ip = $this->klientIp();

        if ($ip === null) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            // 🪤 /56, ikke /64 (review M2): mange udbydere tildeler et /56
            // eller /48, saa et /64 gav op til 256 proever pr. abonnent. De
            // sidste 9 bytes nulstilles. (Tredje argument kraever Symfony 7.2+;
            // vaerten koerer 7.4.)
            $ip = IpUtils::anonymize($ip, 1, 9);
        }

        return 'metis:anon_lookups:ip:'.sha1($ip);
    }
}
