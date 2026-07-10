<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\EmailGate;
use TheFountainhead\Metis\Models\EmailVerification;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::fake();
});

function makeVerification(string $email): void
{
    EmailVerification::create([
        'email' => $email,
        'code' => '123456',
        'expires_at' => now()->addMinutes(10),
        'ip_address' => '127.0.0.1',
    ]);
}

it('attaches the pilot token to the session when a pilot email verifies', function () {
    config(['metis.gating.pilot_users' => 'rasmus@example.com:2|abcDEF123xyz']);
    makeVerification('rasmus@example.com');

    Livewire::test(EmailGate::class)
        ->set('email', 'rasmus@example.com')
        ->set('code', '123456')
        ->call('verifyCode')
        ->assertSet('codeError', false);

    expect(session('metis_verified_email'))->toBe('rasmus@example.com')
        ->and(session('metis_user_token'))->toBe('2|abcDEF123xyz');
});

it('matches pilot emails case-insensitively', function () {
    config(['metis.gating.pilot_users' => 'Rasmus@Example.com:2|abcDEF123xyz']);
    makeVerification('rasmus@example.com');

    Livewire::test(EmailGate::class)
        ->set('email', 'rasmus@example.com')
        ->set('code', '123456')
        ->call('verifyCode');

    expect(session('metis_user_token'))->toBe('2|abcDEF123xyz');
});

it('does not attach a token for non-pilot emails', function () {
    config(['metis.gating.pilot_users' => 'rasmus@example.com:2|abcDEF123xyz']);
    makeVerification('other@example.com');

    Livewire::test(EmailGate::class)
        ->set('email', 'other@example.com')
        ->set('code', '123456')
        ->call('verifyCode');

    expect(session('metis_verified_email'))->toBe('other@example.com')
        ->and(session('metis_user_token'))->toBeNull();
});

it('handles empty pilot config without side effects', function () {
    config(['metis.gating.pilot_users' => '']);
    makeVerification('anyone@example.com');

    Livewire::test(EmailGate::class)
        ->set('email', 'anyone@example.com')
        ->set('code', '123456')
        ->call('verifyCode');

    expect(session('metis_user_token'))->toBeNull();
});
