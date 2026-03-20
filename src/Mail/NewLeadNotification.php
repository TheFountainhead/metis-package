<?php

namespace TheFountainhead\Metis\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use TheFountainhead\Metis\Models\MetisLead;

class NewLeadNotification extends Mailable
{
    public function __construct(
        public MetisLead $lead,
        public string $searchType,
        public string $searchTerm,
    ) {}

    public function envelope(): Envelope
    {
        $company = $this->lead->company_name ?? 'Ukendt virksomhed';

        return new Envelope(
            subject: "Ny Metis-bruger: {$this->lead->email} ({$company})",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'metis::mail.new-lead-notification');
    }
}
