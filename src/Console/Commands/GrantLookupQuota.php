<?php

namespace TheFountainhead\Metis\Console\Commands;

use Illuminate\Console\Command;
use TheFountainhead\Metis\Models\MetisLead;

/**
 * Åbn for flere opslag til én testbruger.
 *
 * 🔑 Frederiks model 10/8: en bruger der loeber toer, trykker paa en knap og
 * sender en besked. Frederik koerer denne kommando og aabner for flere. Saa
 * ser han hvem der rent faktisk bruger Metis, foer der traeffes beslutning om
 * betaling.
 *
 * Kommandoen staar ordret i notifikations-mailen, saa den kan koeres direkte
 * derfra uden at slaa syntaksen op.
 *
 * 🪤 SAETTER kvoten, laegger ikke til. `--tilfoej 10` paa en bruger med ukendt
 * forbrug ville give et resultat man skal regne sig frem til; et absolut tal
 * kan verificeres i outputtet. Frederik skal kunne se hvad han gav.
 */
class GrantLookupQuota extends Command
{
    protected $signature = 'metis:grant-quota
        {email : Testbrugerens email}
        {quota=25 : Nyt samlet antal opslag}';

    protected $description = 'Åbn for flere opslag til en testbruger';

    public function handle(): int
    {
        $email = (string) $this->argument('email');
        $kvote = (int) $this->argument('quota');

        if ($kvote < 1) {
            $this->error('Kvoten skal vaere mindst 1.');

            return self::FAILURE;
        }

        $lead = MetisLead::where('email', $email)->first();

        if (! $lead) {
            $this->error("Ingen testbruger med emailen {$email}.");

            return self::FAILURE;
        }

        $foer = $lead->lookup_quota;

        // 🪤 Nulstiller `quota_requested_at`, saa UI'et ikke bliver ved med at
        // vise "anmodning sendt" efter den er imoedekommet — og saa brugeren
        // kan anmode igen naeste gang de loeber toer.
        $lead->update([
            'lookup_quota' => $kvote,
            'quota_requested_at' => null,
        ]);

        $this->info(sprintf(
            '%s: kvote %d -> %d (brugt %d, altsaa %d tilbage)',
            $email, $foer, $kvote, $lead->lookup_count, max(0, $kvote - $lead->lookup_count)
        ));

        return self::SUCCESS;
    }
}
