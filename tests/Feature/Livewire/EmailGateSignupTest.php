<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\EmailGate;
use TheFountainhead\Metis\Mail\VerificationCode;
use TheFountainhead\Metis\Models\MetisLead;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Baggrunds-firma-opslaget rammer registry-api; returnér tom liste (intet match).
    Http::fake(['*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => []]])]);
    Mail::fake();
    config(['metis.gating.require_business_email' => true]);
});

it('requires a name before sending the code', function () {
    Livewire::test(EmailGate::class)
        ->set('name', '')
        ->set('email', 'someone@virksomhed.dk')
        ->call('sendCode')
        ->assertSet('nameError', true)
        ->assertSet('step', 'email');

    Mail::assertNothingSent();
});

it('rejects a private email domain', function () {
    Livewire::test(EmailGate::class)
        ->set('name', 'Christian Dideriksen')
        ->set('email', 'christian@gmail.com')
        ->call('sendCode')
        ->assertSet('emailError', true)
        ->assertSet('emailErrorMessage', 'free_email')
        ->assertSet('step', 'email');

    Mail::assertNothingSent();
});

it('sends the code and advances to verify for a work email with a name', function () {
    Livewire::test(EmailGate::class)
        ->set('name', 'Christian Dideriksen')
        ->set('email', 'christian@virksomhed.dk')
        ->call('sendCode')
        ->assertSet('step', 'verify')
        ->assertSet('emailError', false);

    Mail::assertSent(VerificationCode::class);
});

it('stores the name on the lead after verification', function () {
    $component = Livewire::test(EmailGate::class)
        ->set('name', 'Christian Dideriksen')
        ->set('email', 'christian@virksomhed.dk')
        ->call('sendCode');

    $code = \TheFountainhead\Metis\Models\EmailVerification::where('email', 'christian@virksomhed.dk')
        ->latest()->first()->code;

    $component->set('code', $code)->call('verifyCode')->assertSet('codeError', false);

    $lead = MetisLead::where('email', 'christian@virksomhed.dk')->first();
    expect($lead)->not->toBeNull()
        ->and($lead->name)->toBe('Christian Dideriksen');
});
