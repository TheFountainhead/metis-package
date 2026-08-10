<?php

namespace TheFountainhead\Metis\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Opbevaringsgraense paa `metis_lookups` — TO trin med hver sin begrundelse.
 *
 * 🚨 MAALT PAA PROD 9/8: 8.265 raekker fra 25. marts til i dag, ALLE med
 * `ip_address`, ingen oprydning nogensinde. Tabellen er en log over hvem der
 * soegte paa hvad — 4½ maaneds komplet soegehistorik.
 *
 * 🔑 HVORFOR TO TRIN OG IKKE ÉT. Felterne har forskellig levetid:
 *
 *   `ip_address` er det ENESTE der peger paa en person, og den mister sin
 *   nytte hurtigt — misbrugsmoenstre ses inden for dage, ikke maaneder.
 *   Anonymiseres efter 30 dage.
 *
 *   Soegetype og tidspunkt er brugbar statistik i hele perioden og siger intet
 *   om hvem. Raekken slettes foerst efter 90 dage.
 *
 * Saa beholdes analysevaerdien i 90 dage, mens det identificerende forsvinder
 * efter 30. Ét samlet tal kunne ikke goere begge dele.
 *
 * 🪤 ANONYMISERING, IKKE SLETNING, af IP'en. Sidste oktet nulstilles
 * (37.96.17.93 -> 37.96.17.0), saa netvaerket — og dermed "kom disse opslag
 * fra samme sted?" — kan stadig besvares uden at pege paa en abonnent. Det er
 * samme greb som Google Analytics' IP-anonymisering.
 */
class PruneMetisLookups extends Command
{
    protected $signature = 'metis:prune-lookups
        {--anonymise-after=30 : Anonymisér ip_address efter N dage}
        {--delete-after=90 : Slet raekken helt efter N dage}
        {--dry-run : Vis hvad der ville ske, skriv intet}';

    protected $description = 'Anonymisér IP efter 30 dage og slet raekker efter 90 i metis_lookups';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $anonAlder = (int) $this->option('anonymise-after');
        $sletAlder = (int) $this->option('delete-after');

        // 🪤 Slet-graensen skal vaere AELDRE end anonymiserings-graensen.
        // Omvendt ville raekkerne blive slettet foer de nogensinde blev
        // anonymiseret, og anonymiseringen ville vaere doed kode der saa ud
        // som beskyttelse.
        if ($sletAlder <= $anonAlder) {
            $this->error("--delete-after ({$sletAlder}) skal vaere stoerre end --anonymise-after ({$anonAlder}).");

            return self::FAILURE;
        }

        $tilAnonymisering = $this->tælTilAnonymisering($anonAlder, $sletAlder);
        $tilSletning = $this->tælTilSletning($sletAlder);

        $this->info(sprintf(
            'Til anonymisering (>%d dage): %s · til sletning (>%d dage): %s',
            $anonAlder, number_format($tilAnonymisering),
            $sletAlder, number_format($tilSletning)
        ));

        if ($dryRun) {
            $this->comment('Toerloeb — intet skrevet.');

            return self::SUCCESS;
        }

        // 🪤 SLET FOERST. Anonymiserer man foerst, bruger man arbejde paa
        // raekker der alligevel forsvinder i naeste skridt.
        $slettet = DB::table('metis_lookups')
            ->where('created_at', '<', now()->subDays($sletAlder))
            ->delete();

        // 🪤 Grupperet pr. RESULTAT frem for ét UPDATE pr. raekke: alle
        // 37.96.17.x bliver til 37.96.17.0 i ét kald. Paa 8.265 raekker er
        // forskellen mellem ~30 UPDATEs og 8.265.
        $anonymiseret = 0;
        $grupper = [];

        foreach ($this->raekkerTilAnonymisering($anonAlder) as $r) {
            $ny = $this->anonymiser((string) $r->ip_address);

            if ($ny !== null) {
                $grupper[$ny][] = $r->id;
            }
        }

        foreach ($grupper as $ny => $ids) {
            $anonymiseret += DB::table('metis_lookups')
                ->whereIn('id', $ids)
                ->update(['ip_address' => $ny]);
        }

        $this->info(sprintf(
            'Slettet: %s · anonymiseret: %s',
            number_format($slettet), number_format($anonymiseret)
        ));

        Log::info('metis:prune-lookups', [
            'deleted' => $slettet,
            'anonymised' => $anonymiseret,
        ]);

        return self::SUCCESS;
    }

    /**
     * Nulstil sidste oktet: 37.96.17.93 -> 37.96.17.0
     *
     * 🪤 Kun IPv4. En IPv6-adresse har ingen punktummer i samme forstand og
     * lades uroert — derfor filtreres paa formatet foerst.
     *
     * 🪤 PHP-side, ikke `regexp_replace` i SQL: den funktion findes i
     * PostgreSQL, men ikke i SQLite som testene koerer paa. En
     * databeskyttelses-kommando der kun kan testes mod prod, kan i praksis
     * ikke testes.
     */
    private function anonymiser(string $ip): ?string
    {
        if (! preg_match('/^(\d{1,3}\.\d{1,3}\.\d{1,3})\.\d{1,3}$/', $ip, $m)) {
            return null;
        }

        return $m[1].'.0';
    }

    /**
     * Raekker hvis IP skal anonymiseres.
     *
     * 🪤 `NOT LIKE '%.0'` gaelder BEGGE veje — her og i taellingen. Uden den
     * ville allerede anonymiserede raekker blive "anonymiseret" hver nat og
     * taelle med i rapporten, saa tallet aldrig faldt til nul og loggen saa ud
     * som om der stadig var arbejde.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function raekkerTilAnonymisering(int $anonAlder): \Illuminate\Support\Collection
    {
        return DB::table('metis_lookups')
            ->where('created_at', '<', now()->subDays($anonAlder))
            ->whereNotNull('ip_address')
            ->where('ip_address', 'not like', '%.0')
            ->select('id', 'ip_address')
            ->get();
    }

    private function tælTilAnonymisering(int $anonAlder, int $sletAlder): int
    {
        return DB::table('metis_lookups')
            ->where('created_at', '<', now()->subDays($anonAlder))
            ->where('created_at', '>=', now()->subDays($sletAlder))
            ->whereNotNull('ip_address')
            ->where('ip_address', 'not like', '%.0')
            ->count();
    }

    private function tælTilSletning(int $sletAlder): int
    {
        return DB::table('metis_lookups')
            ->where('created_at', '<', now()->subDays($sletAlder))
            ->count();
    }
}
