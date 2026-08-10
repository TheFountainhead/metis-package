<?php

namespace TheFountainhead\Metis\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use TheFountainhead\Metis\Mail\AbandonedVerificationsReport;
use TheFountainhead\Metis\Models\EmailVerification;

/**
 * Hvem bad om en kode uden at gennemfoere?
 *
 * 🔑 Frederik 10/8: han vil ogsaa se dem der FALDER FRA. Maalt samme dag: 4
 * personer bad om en kode, 2 blev til leads — halvdelen forsvandt ved
 * kode-trinnet. De to frafaldne (`kristian@frankston.io`,
 * `niels@heurica.dk`) var usynlige indtil nu.
 *
 * 🪤 EN DAGLIG OPSAMLING, IKKE EN MAIL PR. FORSOEG. `email_verifications` har
 * 16 raekker fra 4 personer — en notifikation ved hver kodeanmodning ville
 * altsaa sende fire gange for meget, og "faldt fra" kan foerst afgoeres naar
 * tiden er gaaet. Derfor grupperes pr. email og rapporteres én gang.
 *
 * 🪤 Kun forsoeg der er MERE END EN TIME gamle. En bruger der lige har bedt om
 * sin kode er ikke faldet fra — de laeser den maaske netop nu.
 */
class ReportAbandonedVerifications extends Command
{
    protected $signature = 'metis:report-abandoned
        {--hours=24 : Kig N timer tilbage}
        {--dry-run : Vis hvem der ville blive rapporteret, send intet}';

    protected $description = 'Rapportér hvem der bad om en kode uden at gennemføre';

    public function handle(): int
    {
        $timer = (int) $this->option('hours');
        $dryRun = (bool) $this->option('dry-run');

        $frafaldne = $this->frafaldne($timer);

        $this->info(sprintf('%s paabegyndte uden at gennemfoere (sidste %d timer)',
            number_format($frafaldne->count()), $timer));

        foreach ($frafaldne as $f) {
            $this->line(sprintf('  %s · %d forsoeg · sidst %s',
                $f->email, $f->forsoeg, $f->sidste));
        }

        if ($frafaldne->isEmpty()) {
            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->comment('Toerloeb — intet sendt.');

            return self::SUCCESS;
        }

        if ($til = config('metis.admin.notify_email')) {
            Mail::to($til)->send(new AbandonedVerificationsReport($frafaldne->all(), $timer));
            $this->info('Rapport sendt til '.$til);
        }

        return self::SUCCESS;
    }

    /**
     * Emails der bad om en kode, men aldrig blev et lead.
     *
     * 🪤 `whereNotExists` mod `metis_leads` frem for at sammenligne to lister
     * i PHP: en bruger kan have bedt om koden tre gange og gennemfoert paa
     * fjerde, og en liste-sammenligning ville da rapportere dem som frafaldne.
     * Databasen afgoer det pr. email.
     *
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function frafaldne(int $timer): \Illuminate\Support\Collection
    {
        return EmailVerification::query()
            ->where('email_verifications.created_at', '>', now()->subHours($timer))
            // 🪤 Én time, saa en bruger der netop har faaet sin kode ikke
            // rapporteres som frafalden mens de laeser den.
            ->where('email_verifications.created_at', '<', now()->subHour())
            ->whereNotExists(function ($q) {
                $q->select(DB::raw(1))
                    ->from('metis_leads')
                    ->whereColumn('metis_leads.email', 'email_verifications.email');
            })
            ->groupBy('email_verifications.email')
            ->selectRaw('email_verifications.email, count(*) AS forsoeg, max(email_verifications.created_at) AS sidste')
            ->orderByDesc('sidste')
            ->get();
    }
}
