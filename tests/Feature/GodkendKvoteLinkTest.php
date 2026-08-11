<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use TheFountainhead\Metis\Models\MetisLead;

uses(RefreshDatabase::class);

/**
 * Godkendelses-link i kvote-mailen.
 *
 * 🚨 Foer denne rute var godkendelse en SSH-session: mailen gav Frederik en
 * `php artisan metis:grant-quota <email> 25` han skulle koere paa serveren.
 * Friktionen betyder at anmodninger bliver liggende — og en testbruger der
 * venter to dage paa svar er tabt. Larnaes brugte 2 af 25 opslag 10/8 og kom
 * ikke igen; naeste anmodning maa ikke strande i en indbakke.
 *
 * 🪤 Ruten er AUTH-FRI (signeret link, aabnes fra telefonen). Derfor er
 * halvdelen af testene her sikkerhedstests, ikke funktionstests:
 *
 *   - uden gyldig signatur => 403 (ellers kan kvoten haeves ved at gaette id)
 *   - `NoIndex` paa gruppen => linket maa aldrig havne i et soegeindeks
 *
 * Samme fejlklasse som CPR-ruterne 9/8: en auth-fri rute er kun sikker saa
 * laenge BAADE signaturen og noindex sidder paa den. Guarden skal matche det
 * den beskytter.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.admin.notify_email', 'fred@frankston.io');
    Mail::fake();
});

it('🚨 haever kvoten naar Frederik aabner det signerede link', function () {
    $lead = MetisLead::create([
        'email' => 'test@eksempel.dk',
        'lookup_count' => 5,
        'lookup_quota' => 5,
        'quota_requested_at' => now(),
    ]);

    $this->get(URL::signedRoute('metis.grant-quota', ['lead' => $lead->id, 'quota' => 25]))
        ->assertOk();

    expect($lead->fresh()->lookup_quota)->toBe(25);
});

it('🚨 afviser et link UDEN gyldig signatur', function () {
    // Kernen i en auth-fri rute: uden signaturen ville et gaettet id vaere nok
    // til at give sig selv fri adgang til produktet.
    $lead = MetisLead::create([
        'email' => 'test@eksempel.dk',
        'lookup_count' => 5,
        'lookup_quota' => 5,
    ]);

    $this->get(route('metis.grant-quota', ['lead' => $lead->id, 'quota' => 25]))
        ->assertForbidden();

    expect($lead->fresh()->lookup_quota)->toBe(5);
});

it('🚨 afviser et MANIPULERET link (kvoten skruet op efter signering)', function () {
    // Signaturen daekker HELE URL en inkl. query. Aendrer man 25 til 9999
    // efter signering, holder signaturen ikke laengere.
    $lead = MetisLead::create([
        'email' => 'test@eksempel.dk',
        'lookup_count' => 5,
        'lookup_quota' => 5,
    ]);

    $url = URL::signedRoute('metis.grant-quota', ['lead' => $lead->id, 'quota' => 25]);

    // 🪤 `quota` ligger i STIEN (/godkend-kvote/1/25), ikke i query-strengen.
    // Foerste udgave af denne test erstattede "quota=25" — en streng der slet
    // ikke findes i URL'en — saa den aendrede intet og bestod paa et 200-svar
    // uden at maale noget. Manipulér stien, ellers tester den ingenting.
    $manipuleret = str_replace("/{$lead->id}/25?", "/{$lead->id}/9999?", $url);
    expect($manipuleret)->not->toBe($url); // beviser at vi FAKTISK aendrede noget

    $this->get($manipuleret)->assertForbidden();

    expect($lead->fresh()->lookup_quota)->toBe(5);
});

it('🚨 saetter noindex saa godkendelses-linket aldrig indekseres', function () {
    $lead = MetisLead::create(['email' => 'test@eksempel.dk', 'lookup_count' => 5, 'lookup_quota' => 5]);

    $this->get(URL::signedRoute('metis.grant-quota', ['lead' => $lead->id, 'quota' => 25]))
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('giver brugeren besked om at der er aabnet', function () {
    // Uden denne mail opdager brugeren det kun ved at proeve igen — og en
    // testbruger der ikke ved at doeren er aabnet, kommer ikke tilbage.
    $lead = MetisLead::create([
        'email' => 'test@eksempel.dk',
        'lookup_count' => 5,
        'lookup_quota' => 5,
        'quota_requested_at' => now(),
    ]);

    $this->get(URL::signedRoute('metis.grant-quota', ['lead' => $lead->id, 'quota' => 25]))->assertOk();

    Mail::assertSent(\TheFountainhead\Metis\Mail\QuotaGrantedNotification::class,
        fn ($m) => $m->hasTo('test@eksempel.dk'));
});

it('🪤 rydder quota_requested_at saa knappen kan bruges igen senere', function () {
    // Ellers ville brugeren staa med en permanent "din anmodning er sendt" og
    // aldrig kunne bede om mere, naar de nye opslag ogsaa er brugt.
    $lead = MetisLead::create([
        'email' => 'test@eksempel.dk',
        'lookup_count' => 5,
        'lookup_quota' => 5,
        'quota_requested_at' => now(),
    ]);

    $this->get(URL::signedRoute('metis.grant-quota', ['lead' => $lead->id, 'quota' => 25]))->assertOk();

    expect($lead->fresh()->quota_requested_at)->toBeNull();
});
