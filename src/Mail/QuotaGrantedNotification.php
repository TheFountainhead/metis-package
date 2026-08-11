<?php

namespace TheFountainhead\Metis\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use TheFountainhead\Metis\Models\MetisLead;

/**
 * "Der er aabnet for flere opslag" — til TESTBRUGEREN.
 *
 * 🚨 Uden denne mail opdager brugeren kun aabningen ved at proeve igen. Én der
 * har faaet "din anmodning er sendt" og intet hoerer, proever typisk ikke igen
 * — og saa er godkendelsen spildt.
 */
class QuotaGrantedNotification extends Mailable
{
    use Queueable, SerializesModels;

    public function __construct(public MetisLead $lead) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Metis: der er åbnet for flere opslag');
    }

    public function content(): Content
    {
        return new Content(text: 'metis::mail.quota-granted');
    }
}
