<?php

namespace TheFountainhead\Metis\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use TheFountainhead\Metis\Mail\QuotaGrantedNotification;
use TheFountainhead\Metis\Models\MetisLead;

/**
 * Ét klik i mailen aabner for flere opslag.
 *
 * 🚨 Foer denne rute var godkendelse en SSH-session: mailen gav en
 * `php artisan metis:grant-quota <email> 25` der skulle koeres paa serveren.
 * En anmodning der kraever en terminal bliver liggende, og en testbruger der
 * venter to dage er tabt.
 *
 * 🪤 AUTH-FRI, beskyttet af signaturen alene. Det er et bevidst valg: linket
 * skal kunne aabnes fra telefonen uden login. Signaturen daekker HELE URL'en
 * inkl. `quota`, saa hverken id'et eller tallet kan aendres bagefter. Ruten
 * ligger i `NoIndex`-gruppen — en auth-fri rute uden noindex var praecis
 * CPR-hullet 9/8, hvor beskyttelsen sad paa den ene af to veje ind.
 */
class GrantQuotaController
{
    public function __invoke(Request $request, int $lead, int $quota)
    {
        $model = MetisLead::find($lead);

        if (! $model) {
            // Ingen 404-detaljer: linket er auth-frit, saa svaret maa ikke
            // roebe hvilke lead-id'er der findes.
            abort(404);
        }

        $foer = $model->lookup_quota;

        $model->update([
            'lookup_quota' => $quota,
            // 🪤 Nulstilles, saa brugeren kan anmode IGEN naar de nye opslag er
            // brugt. Ellers ville "din anmodning er sendt" staa permanent.
            'quota_requested_at' => null,
        ]);

        Log::info('metis.quota.granted', [
            'lead_id' => $model->id, 'foer' => $foer, 'efter' => $quota,
        ]);

        // 🪤 `rescue()`: en mailfejl maa ikke rulle godkendelsen tilbage.
        // Kvoten ER haevet, og det er det vigtigste for brugeren.
        rescue(fn () => Mail::to($model->email)->send(new QuotaGrantedNotification($model)));

        return response()->view('metis::quota-granted', [
            'lead' => $model,
            'foer' => $foer,
        ]);
    }
}
