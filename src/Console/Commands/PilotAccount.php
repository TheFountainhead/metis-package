<?php

namespace TheFountainhead\Metis\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;
use TheFountainhead\Metis\Models\MetisPilotAccount;

class PilotAccount extends Command
{
    protected $signature = 'metis:pilot-account {email : Pilotens arbejdsmail}
        {--name= : Navn}
        {--token= : registry-api-token (kræves ved oprettelse)}
        {--password= : Kodeord; udelades det, laves et og vises én gang}';

    protected $description = 'Opret eller opdatér en pilotkonto (mail + kodeord) med registry-api-token bagved.';

    public function handle(): int
    {
        $email = strtolower(trim((string) $this->argument('email')));
        $account = MetisPilotAccount::whereRaw('lower(email) = ?', [$email])->first();

        if (! $account && ! $this->option('token')) {
            $this->error('Ny konto kræver --token=<registry-api-token>.');

            return self::FAILURE;
        }

        $password = (string) ($this->option('password') ?: Str::password(14, symbols: false));

        $attributes = ['password' => $password];
        if ($this->option('name')) {
            $attributes['name'] = $this->option('name');
        }
        if ($this->option('token')) {
            $attributes['registry_token'] = $this->option('token');
        }

        $account = $account
            ? tap($account)->update($attributes)
            : MetisPilotAccount::create(['email' => $email] + $attributes);

        // Kodeordet vises kun her, én gang. Det gemmes hashet.
        $this->info(($account->wasRecentlyCreated ? 'Oprettet' : 'Opdateret').": {$account->email}");
        $this->line("Kodeord: {$password}");
        $this->line('Send det til piloten ad en anden kanal end mailen. Login: /log-ind');

        return self::SUCCESS;
    }
}
