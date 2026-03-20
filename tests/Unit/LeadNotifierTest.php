<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use TheFountainhead\Metis\Mail\NewLeadNotification;
use TheFountainhead\Metis\Models\MetisLead;
use TheFountainhead\Metis\Services\LeadNotifier;

uses(RefreshDatabase::class);

it('sends notification email when lead is created', function () {
    Mail::fake();
    config(['metis.admin.notify_email' => 'fred@frankston.io']);

    $lead = MetisLead::create([
        'email' => 'anne@carlsberg.dk',
        'cvr' => '25020913',
        'company_name' => 'Carlsberg A/S',
        'industry' => 'Fremstilling af øl',
    ]);

    app(LeadNotifier::class)->notify($lead, 'address', 'Bredgade 25');

    Mail::assertSent(NewLeadNotification::class, fn ($mail) =>
        $mail->hasTo('fred@frankston.io')
    );
});

it('does not send when notify_email is empty', function () {
    Mail::fake();
    config(['metis.admin.notify_email' => null]);

    $lead = MetisLead::create([
        'email' => 'test@test.dk',
        'cvr' => '99999999',
        'company_name' => 'Test ApS',
    ]);

    app(LeadNotifier::class)->notify($lead, 'cvr', '99999999');

    Mail::assertNothingSent();
});
