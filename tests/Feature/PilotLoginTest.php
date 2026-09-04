<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\PilotLogin;
use TheFountainhead\Metis\Models\MetisPilotAccount;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
});

function pilotAccount(string $password = 'hemmeligt-kodeord'): MetisPilotAccount
{
    return MetisPilotAccount::create([
        'email' => 'rasmus@laangiver.test',
        'name' => 'Rasmus Test',
        'password' => $password,
        'registry_token' => '19|registry-token-abc',
    ]);
}

it('log ind med mail og kodeord hæfter tokenet på sessionen og sender videre til engagementer', function () {
    pilotAccount();

    Livewire::test(PilotLogin::class)
        ->set('email', 'Rasmus@Laangiver.test')
        ->set('password', 'hemmeligt-kodeord')
        ->call('login')
        ->assertRedirect(route('metis.engagements'));

    expect(session('metis_user_token'))->toBe('19|registry-token-abc')
        ->and(session('metis_verified_email'))->toBe('rasmus@laangiver.test')
        ->and(MetisPilotAccount::first()->remember_token)->not->toBeNull();
});

it('forkert kodeord og ukendt mail giver samme svar, og efter fem forsøg låses der', function () {
    pilotAccount();

    for ($i = 0; $i < 5; $i++) {
        Livewire::test(PilotLogin::class)->set('email', 'rasmus@laangiver.test')->set('password', 'forkert')
            ->call('login')->assertSet('error', 'Mail eller kodeord passer ikke.');
    }
    Livewire::test(PilotLogin::class)->set('email', 'ukendt@laangiver.test')->set('password', 'x')
        ->call('login')->assertSee('passer ikke');
    Livewire::test(PilotLogin::class)->set('email', 'rasmus@laangiver.test')->set('password', 'hemmeligt-kodeord')
        ->call('login')->assertSee('For mange forsøg');

    expect(session('metis_user_token'))->toBeNull();
});

it('husk-mig-cookien genskaber sessionen, og log-ud fjerner den', function () {
    $account = pilotAccount();
    $secret = 'remember-secret';
    $account->forceFill(['remember_token' => Hash::make($secret)])->save();

    $this->withUnencryptedCookie(PilotLogin::REMEMBER_COOKIE, $account->id.'|'.$secret)
        ->get('/engagementer')
        ->assertOk()
        ->assertDontSee('Kun for pilotbrugere');

    expect(session('metis_user_token'))->toBe('19|registry-token-abc');

    $this->get('/log-ud')->assertRedirect(route('metis.home'));
    expect(session('metis_user_token'))->toBeNull()
        ->and($account->fresh()->remember_token)->toBeNull();

    // Cookien alene kan ikke logge ind igen efter log-ud.
    $this->withUnencryptedCookie(PilotLogin::REMEMBER_COOKIE, $account->id.'|'.$secret)->get('/engagementer')->assertSee('Kun for pilotbrugere');
});

it('en forkert eller manipuleret husk-mig-cookie giver ingen session', function () {
    $account = pilotAccount();
    $account->forceFill(['remember_token' => Hash::make('rigtig')])->save();

    $this->withUnencryptedCookie(PilotLogin::REMEMBER_COOKIE, $account->id.'|forkert')->get('/engagementer')->assertSee('Kun for pilotbrugere');
    $this->withUnencryptedCookie(PilotLogin::REMEMBER_COOKIE, 'abc|rigtig')->get('/engagementer')->assertSee('Kun for pilotbrugere');

    expect(session('metis_user_token'))->toBeNull();
});

it('metis:pilot-account opretter kontoen med hashet kodeord og krypteret token, og kræver token ved oprettelse', function () {
    $this->artisan('metis:pilot-account', ['email' => 'ny@laangiver.test'])->assertFailed();

    $this->artisan('metis:pilot-account', ['email' => 'Ny@Laangiver.test', '--token' => '20|tok', '--password' => 'valgt-kodeord', '--name' => 'Ny Pilot'])
        ->expectsOutputToContain('Kodeord: valgt-kodeord')
        ->assertSuccessful();

    $a = MetisPilotAccount::first();
    expect($a->email)->toBe('ny@laangiver.test')
        ->and(Hash::check('valgt-kodeord', $a->password))->toBeTrue()
        ->and($a->registry_token)->toBe('20|tok')
        ->and($a->getRawOriginal('registry_token'))->not->toContain('20|tok');
});
