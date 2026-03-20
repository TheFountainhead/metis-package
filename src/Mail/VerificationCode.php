<?php

namespace TheFountainhead\Metis\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

class VerificationCode extends Mailable
{
    use Queueable;

    public function __construct(public string $code) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: 'Din Metis-verifikationskode');
    }

    public function content(): Content
    {
        return new Content(view: 'metis::mail.verification-code');
    }
}
