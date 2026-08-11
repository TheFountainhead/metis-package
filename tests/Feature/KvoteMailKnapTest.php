<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use TheFountainhead\Metis\Mail\QuotaRequestNotification;
use TheFountainhead\Metis\Models\MetisLead;

uses(RefreshDatabase::class);

/**
 * Godkendelses-KNAP i anmodnings-mailen.
 *
 * Foerste udgave lagde linket ind som raa URL i en tekst-mail. Det virker,
 * men Frederik skal stadig finde og ramme en lang URL-streng paa en telefon.
 * En knap er forskellen paa "jeg goer det senere" og "jeg goer det nu" — og
 * hele pointen med linket var at fjerne friktion fra godkendelsen.
 *
 * 🪤 Mailen skal have BEGGE dele: HTML med knap til normale klienter, og en
 * ren tekst-udgave med URL'en til dem der viser plain text. Uden tekst-delen
 * ville en plaintext-klient vise en knap uden nogen maade at klikke paa.
 */
beforeEach(function () {
    config()->set('metis.admin.notify_email', 'fred@frankston.io');
});

function anmodendeLead(): MetisLead
{
    return MetisLead::create([
        'email' => 'test@eksempel.dk',
        'company_name' => 'Eksempel ApS',
        'lookup_count' => 5,
        'lookup_quota' => 5,
        'quota_requested_at' => now(),
    ]);
}

it('🚨 har en klikbar godkendelses-knap i HTML-udgaven', function () {
    $html = (new QuotaRequestNotification(anmodendeLead()))->render();

    expect($html)->toContain('godkend-kvote')
        ->and($html)->toMatch('/<a[^>]+href="[^"]*godkend-kvote[^"]*"/');
});

it('🚨 knappen peger paa et SIGNERET link', function () {
    // Uden signaturen ville knappen vaere et aabent endpoint hvor som helst
    // mailen videresendes eller laekker.
    $html = (new QuotaRequestNotification(anmodendeLead()))->render();

    expect($html)->toContain('signature=')
        ->and($html)->toContain('expires=');
});

it('🪤 har ogsaa en ren tekst-udgave med URL en', function () {
    // En plaintext-klient viser ingen knap. Uden tekst-delen ville mailen
    // vaere ubrugelig dér — og godkendelsen umulig.
    //
    // 🪤 `build()` og `toMail()` findes ikke paa moderne mailables, og
    // egenskaben hedder `text` — ikke `textView`. Vi laeser derfor `content()`
    // og rendrer selve viewet, saa testen daekker BAADE at tekst-udgaven er
    // erklaeret OG at den faktisk baerer linket.
    $lead = anmodendeLead();
    $mail = new QuotaRequestNotification($lead);

    // Mailen SKAL erklaere et tekst-view — ellers findes plaintext-udgaven ikke.
    expect($mail->content()->text)->toBe('metis::mail.quota-request-notification-text');

    // ...og det view skal faktisk baere det signerede link.
    $tekst = view($mail->content()->text, $mail->content()->with + ['lead' => $lead])->render();

    expect($tekst)->toContain('godkend-kvote')
        ->and($tekst)->toContain('signature=');
});

it('baerer forbruget saa Frederik kan prioritere fra indbakken', function () {
    $mail = new QuotaRequestNotification(anmodendeLead());

    expect($mail->envelope()->subject)->toContain('5 af 5')
        ->and($mail->envelope()->subject)->toContain('Eksempel ApS');
});
