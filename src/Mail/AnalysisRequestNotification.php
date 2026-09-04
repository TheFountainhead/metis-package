<?php

namespace TheFountainhead\Metis\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use TheFountainhead\Metis\Models\MetisAnalysisRequest;

class AnalysisRequestNotification extends Mailable
{
    public function __construct(
        public MetisAnalysisRequest $request,
    ) {}

    public function envelope(): Envelope
    {
        $hvem = $this->request->company_name ?: $this->request->email;

        return new Envelope(
            subject: "Metis: analyse bestilt af {$hvem} ({$this->request->purposeLabel()})",
        );
    }

    public function content(): Content
    {
        return new Content(
            view: 'metis::mail.analysis-request-notification',
            text: 'metis::mail.analysis-request-notification-text',
        );
    }
}
