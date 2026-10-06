<?php

namespace TheFountainhead\Metis\Services;

use Illuminate\Support\Facades\Cache;
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
     * Koerer vi i en web-request med en session?
     *
     * 🪤 Baggrundsjob og kommandoer har ingen session og skal ikke rammes.
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

        return filled(session('metis_verified_email'));
    }

    /**
     * Er brugeren fritaget fra den ANONYMES gratis-kvote?
     *
     * Uaendret semantik: pilot-token eller verificeret mail. Verificerede
     * brugere har deres egen lead-kvote, som `GatesLookups` haandhaever.
     */
    public function erKvoteFritaget(): bool
    {
        if (! $this->gatingAktiv()) {
            return true;
        }

        return filled(session('metis_user_token')) || filled(session('metis_verified_email'));
    }

    public function graense(): int
    {
        return (int) config('metis.gating.free_lookups', 1);
    }

    /** DEN ENE taerskel. Se klassens docblock. */
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

        return $noegle === null ? 0 : (int) Cache::get($noegle, 0);
    }

    /**
     * Siden: maa en anonym besoegende STARTE et nyt opslag?
     *
     * Det hoejeste af session- og IP-taellingen afgoer. Ny cookie nulstiller
     * sessionen, men ikke IP'en.
     */
    public function anonymtOpslagTilladt(): bool
    {
        return $this->opslagNrErTilladt(max($this->brugtISession(), $this->brugtFraIp()) + 1);
    }

    /**
     * Datalaget: har denne session brugt flere opslag end den har faaet?
     *
     * 🪤 KUN sessionen her, ikke IP'en. To kolleger bag samme kontor-IP der
     * starter et opslag samtidig, kan begge passere siden (begge laeste 0),
     * og IP-taelleren staar da paa 2. Talte datalaget IP'en med, ville BEGGE
     * deres allerede tilladte opslag fejle i alle sektioner. IP-bindingen
     * sidder paa siden, hvor nye opslag (og dermed nye lazy-payloads) udstedes.
     */
    public function anonymKvoteOverskredet(): bool
    {
        if (! $this->gatingAktiv() || ! $this->harSession() || $this->erKvoteFritaget()) {
            return false;
        }

        return ! $this->opslagNrErTilladt($this->brugtISession());
    }

    /**
     * Tael et anonymt opslag paa IP'en. Sessionstaelleren ejes af kalderen.
     *
     * Vinduet er fast fra foerste opslag (`Cache::add` saetter udloebet, og
     * `increment` bevarer det), som standard 24 timer.
     */
    public function taelAnonymtOpslagPaaIp(): void
    {
        if ($this->erKvoteFritaget()) {
            return;
        }

        $noegle = $this->ipNoegle();

        if ($noegle === null) {
            return;
        }

        // 🪤 `rescue()`: en fejlet cache maa ikke koste brugeren opslaget.
        rescue(function () use ($noegle) {
            Cache::add($noegle, 0, now()->addHours((int) config('metis.gating.ip_window_hours', 24)));
            Cache::increment($noegle);
        }, null, false);
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
     * 🪤 IPv6 bindes paa /64. En almindelig IPv6-forbindelse faar et helt
     * /64-net og kan skifte adresse inden for det efter forgodtbefindende;
     * en noegle pr. fuld adresse ville vaere et nyt gratis opslag pr. adresse.
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
            // Standarden nulstiller de sidste 8 bytes af en IPv6, altsaa /64.
            $ip = IpUtils::anonymize($ip);
        }

        return 'metis:anon_lookups:ip:'.sha1($ip);
    }
}
