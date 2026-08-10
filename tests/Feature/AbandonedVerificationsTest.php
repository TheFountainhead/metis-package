<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use TheFountainhead\Metis\Mail\AbandonedVerificationsReport;
use TheFountainhead\Metis\Models\EmailVerification;
use TheFountainhead\Metis\Models\MetisLead;

uses(RefreshDatabase::class);

/**
 * "Hvem bad om adgang uden at gennemføre?" — Frederiks ønske 10/8.
 *
 * 🔑 Målt samme dag: 4 personer bad om en kode, kun 2 blev til leads. De to
 * frafaldne var usynlige — der fandtes ingen notifikation for dem.
 */
beforeEach(function () {
    config()->set('metis.admin.notify_email', 'fred@frankston.io');
    Mail::fake();
});

function kodeanmodning(string $email, int $timerSiden = 3): EmailVerification
{
    $v = EmailVerification::create([
        'email' => $email,
        'code' => '123456',
        'expires_at' => now()->addMinutes(10),
        'ip_address' => '10.0.0.1',
    ]);

    // 🪤 `created_at` er ikke `$fillable`, saa den kan ikke saettes i
    // `create()` — raekken ville faa NU som tidsstempel og falde uden for
    // én-times-vinduet. Foerste udkast gjorde netop det, og testen fandt nul
    // frafaldne uden at koden fejlede.
    $v->forceFill([
        'created_at' => now()->subHours($timerSiden),
        'updated_at' => now()->subHours($timerSiden),
    ])->save();

    return $v->fresh();
}

it('🔑 rapporterer en der bad om kode uden at gennemfoere', function () {
    kodeanmodning('niels@heurica.dk');

    $this->artisan('metis:report-abandoned')->assertSuccessful();

    Mail::assertSent(AbandonedVerificationsReport::class);
});

it('🚨 rapporterer IKKE en der gennemfoerte', function () {
    // Kernen: et lead betyder at de kom igennem.
    kodeanmodning('fl@inovabolighandel.dk');
    MetisLead::create(['email' => 'fl@inovabolighandel.dk', 'lookup_count' => 0]);

    $this->artisan('metis:report-abandoned')->assertSuccessful();

    Mail::assertNothingSent();
});

it('🪤 rapporterer IKKE en der lige har faaet sin kode', function () {
    // De laeser den maaske netop nu. Under én time = ikke faldet fra.
    kodeanmodning('nyeste@example.dk', 0);

    $this->artisan('metis:report-abandoned')->assertSuccessful();

    Mail::assertNothingSent();
});

it('🪤 taeller FLERE forsoeg fra samme person som ÉN raekke', function () {
    // Maalt paa prod: 16 raekker fra 4 personer. En mail pr. raekke ville
    // sende fire gange for meget.
    kodeanmodning('ivrig@example.dk', 5);
    kodeanmodning('ivrig@example.dk', 4);
    kodeanmodning('ivrig@example.dk', 3);

    $this->artisan('metis:report-abandoned --dry-run')
        ->expectsOutputToContain('1 paabegyndte')
        ->assertSuccessful();
});

it('🚨 en der forsoegte tre gange og LYKKEDES paa fjerde rapporteres ikke', function () {
    // 🪤 Derfor `whereNotExists` mod metis_leads frem for at sammenligne to
    // lister i PHP: listen ville indeholde emailen tre gange og se den som
    // frafalden, selv om de kom igennem til sidst.
    kodeanmodning('sej@example.dk', 5);
    kodeanmodning('sej@example.dk', 4);
    MetisLead::create(['email' => 'sej@example.dk', 'lookup_count' => 2]);

    $this->artisan('metis:report-abandoned')->assertSuccessful();

    Mail::assertNothingSent();
});

it('sender INTET naar der ingen frafaldne er', function () {
    // 🪤 En daglig "0 frafaldne"-mail ville laere modtageren at ignorere
    // emnelinjen — og saa forsvinder signalet den dag der ER nogen.
    $this->artisan('metis:report-abandoned')->assertSuccessful();

    Mail::assertNothingSent();
});

it('toerloeb sender intet', function () {
    kodeanmodning('niels@heurica.dk');

    $this->artisan('metis:report-abandoned --dry-run')->assertSuccessful();

    Mail::assertNothingSent();
});

it('--hours afgraenser vinduet', function () {
    kodeanmodning('gammel@example.dk', 100);

    $this->artisan('metis:report-abandoned --hours=24 --dry-run')
        ->expectsOutputToContain('0 paabegyndte')
        ->assertSuccessful();
});
