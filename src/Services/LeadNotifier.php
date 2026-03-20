<?php

namespace TheFountainhead\Metis\Services;

use Illuminate\Support\Facades\Mail;
use TheFountainhead\Metis\Mail\NewLeadNotification;
use TheFountainhead\Metis\Models\MetisLead;

class LeadNotifier
{
    public function notify(MetisLead $lead, string $searchType, string $searchTerm): void
    {
        $email = config('metis.admin.notify_email');

        if (! $email) {
            return;
        }

        Mail::to($email)->send(new NewLeadNotification($lead, $searchType, $searchTerm));
    }
}
