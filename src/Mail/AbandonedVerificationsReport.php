<?php

namespace TheFountainhead\Metis\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * "N bad om en kode uden at gennemfoere."
 *
 * 🔑 Frederik 10/8: han vil ogsaa se dem der falder fra. Maalt samme dag: 4
 * bad om en kode, 2 blev til leads. De frafaldne var usynlige.
 *
 * 🪤 Sendes KUN naar der er nogen at rapportere — kommandoen returnerer foer
 * mailen ellers. En daglig "0 frafaldne"-mail ville laere modtageren at
 * ignorere emnelinjen, og saa forsvinder signalet den dag der ER nogen.
 */
class AbandonedVerificationsReport extends Mailable
{
    /**
     * @param  array<int, object>  $frafaldne
     */
    public function __construct(
        public array $frafaldne,
        public int $timer,
    ) {}

    public function envelope(): Envelope
    {
        $n = count($this->frafaldne);

        return new Envelope(
            subject: "Metis: {$n} bad om adgang uden at gennemføre",
        );
    }

    public function content(): Content
    {
        return new Content(view: 'metis::mail.abandoned-verifications-report');
    }
}
