<?php

namespace TheFountainhead\Metis\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use TheFountainhead\Metis\Models\MetisPilotAccount;

/**
 * Opret eller opdatér en pilotkonto.
 *
 * Hemmeligheder tager kommandoen ALDRIG som argumenter (de ender i shell-
 * historik og kommando-logs): registry-tokenet læses fra metis.gating.pilot_users
 * (`--token-from-pilot-users`) eller fra stdin (`--token-stdin`), og kodeordet
 * genereres og vises én gang, eller læses fra stdin (`--password-stdin`).
 * Eksisterende kodeord bevares, medmindre `--reset-password` er sat.
 * Ændres kodeord eller token, ugyldiggøres husk-mig-nøglen, så en gammel
 * enhed ikke arver det nye token.
 */
class PilotAccount extends Command
{
    protected $signature = 'metis:pilot-account {email : Pilotens arbejdsmail}
        {--name= : Navn}
        {--token-from-pilot-users : Hent registry-tokenet fra METIS_PILOT_USERS for denne mail}
        {--token-stdin : Læs registry-tokenet fra stdin (én linje)}
        {--reset-password : Lav et nyt kodeord (vises én gang)}
        {--password-stdin : Læs kodeordet fra stdin (én linje) i stedet for at generere}';

    protected $description = 'Opret eller opdatér en pilotkonto (mail + kodeord) med registry-api-token bagved.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $account = MetisPilotAccount::whereRaw('lower(email) = ?', [$email])->first();

        $token = null;
        if ($this->option('token-from-pilot-users')) {
            $token = $this->tokenFromPilotUsers($email);
            if (! $token) {
                $this->error("Ingen token for {$email} i metis.gating.pilot_users.");

                return self::FAILURE;
            }
        } elseif ($this->option('token-stdin')) {
            $token = trim((string) fgets(STDIN));
        }

        if (! $account && ! $token) {
            $this->error('Ny konto kræver et token: --token-from-pilot-users eller --token-stdin.');

            return self::FAILURE;
        }

        $password = null;
        if ($this->option('password-stdin')) {
            $password = trim((string) fgets(STDIN));
        } elseif (! $account || $this->option('reset-password')) {
            $password = Str::password(14, symbols: false);
        }

        $attributes = [];
        if ($this->option('name')) {
            $attributes['name'] = $this->option('name');
        }
        if ($token) {
            $attributes['registry_token'] = $token;
        }
        if ($password) {
            $attributes['password'] = $password;
        }
        if ($token || $password) {
            $attributes['remember_token'] = null;
        }

        $account = $account
            ? tap($account)->update($attributes)
            : MetisPilotAccount::create(['email' => $email] + $attributes);

        $this->info(($account->wasRecentlyCreated ? 'Oprettet' : 'Opdateret').": {$account->email}");
        if ($password) {
            // Vises kun her, én gang; gemmes hashet. Send det ad en anden kanal end mailen.
            $this->line("Kodeord: {$password}");
        }
        if ($token || $password) {
            $this->line('Husk-mig-nøglen er nulstillet; eksisterende enheder skal logge ind igen.');
        }
        $this->line('Login: /log-ind');

        return self::SUCCESS;
    }

    private function tokenFromPilotUsers(string $email): ?string
    {
        foreach (explode(',', (string) config('metis.gating.pilot_users', '')) as $pair) {
            [$pilotEmail, $token] = array_pad(explode(':', trim($pair), 2), 2, null);
            if ($token && strcasecmp((string) $pilotEmail, $email) === 0) {
                return $token;
            }
        }

        return null;
    }
}
