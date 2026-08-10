<?php

namespace TheFountainhead\Metis\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use TheFountainhead\Metis\Models\MetisLead;

/**
 * "N har brugt sine opslag og beder om flere."
 *
 * 🔑 Frederiks model 10/8: en testbruger der loeber toer, trykker paa en knap
 * frem for at moede en betalingsmur. Beskeden her er signalet om hvem der
 * rent faktisk BRUGER Metis — ikke hvem der kiggede én gang.
 *
 * 🪤 Emnelinjen baerer forbruget, ikke kun navnet. Frederik skal kunne
 * prioritere fra indbakke-listen uden at aabne hver mail: en der har brugt
 * 5 af 5 er et andet signal end en der har brugt 40 af 40.
 */
class QuotaRequestNotification extends Mailable
{
    public function __construct(
        public MetisLead $lead,
    ) {}

    public function envelope(): Envelope
    {
        $navn = $this->lead->company_name ?: $this->lead->email;

        return new Envelope(
            subject: "Metis: {$navn} beder om flere opslag ({$this->lead->lookup_count} af {$this->lead->lookup_quota} brugt)",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'metis::mail.quota-request-notification');
    }
}
