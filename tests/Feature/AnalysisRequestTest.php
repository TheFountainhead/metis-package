<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\AnalysisRequest;
use TheFountainhead\Metis\Mail\AnalysisRequestNotification;
use TheFountainhead\Metis\Models\MetisAnalysisRequest;

uses(RefreshDatabase::class);

beforeEach(function () {
    Http::preventStrayRequests();
    Http::fake();
    Mail::fake();
    config()->set('metis.admin.notify_email', 'metis@example.test');
});

function analysisFields(array $overrides = []): array
{
    return array_replace([
        'question' => 'Erhvervsejendomme i 2100 med tinglyst gæld over 10 mio. kr. og rente over 10 %.',
        'area' => '2100',
        'purpose' => 'kreditvurdering',
        'email' => 'rasmus@laangiver.test',
        'name' => 'Rasmus Test',
        'company' => 'Långiver Test A/S',
    ], $overrides);
}

it('/spoerg er en bestillingsformular, ikke en live søgning: intet API-kald', function () {
    $c = Livewire::test(AnalysisRequest::class)->assertSee('Bestil en analyse')->assertSee('Prissættes pr. opgave');
    foreach (analysisFields() as $k => $v) {
        $c->set($k, $v);
    }
    $c->call('submit')->assertSet('sent', true)->assertSee('Tak, vi har modtaget bestillingen');

    Http::assertNothingSent();
    expect(MetisAnalysisRequest::count())->toBe(1);
    $req = MetisAnalysisRequest::first();
    expect($req->purpose)->toBe('kreditvurdering')->and($req->email)->toBe('rasmus@laangiver.test');
    Mail::assertSent(AnalysisRequestNotification::class, fn ($m) => $m->hasTo('metis@example.test') && $m->request->is($req));
});

it('afviser fri-mail og manglende formål uden at gemme noget', function () {
    $c = Livewire::test(AnalysisRequest::class);
    foreach (analysisFields(['email' => 'nogen@gmail.com']) as $k => $v) {
        $c->set($k, $v);
    }
    $c->call('submit')->assertSet('sent', false)->assertSee('arbejdsmail');

    $c2 = Livewire::test(AnalysisRequest::class);
    foreach (analysisFields(['purpose' => '']) as $k => $v) {
        $c2->set($k, $v);
    }
    $c2->call('submit')->assertHasErrors(['purpose'])->assertSet('sent', false);

    expect(MetisAnalysisRequest::count())->toBe(0);
    Mail::assertNothingSent();
});

it('forudfylder mail fra en verificeret session', function () {
    session(['metis_verified_email' => 'kendt@laangiver.test']);

    Livewire::test(AnalysisRequest::class)->assertSet('email', 'kendt@laangiver.test');
});

it('højst fem bestillinger pr. mail pr. dag', function () {
    for ($i = 0; $i < 5; $i++) {
        $c = Livewire::test(AnalysisRequest::class);
        foreach (analysisFields() as $k => $v) {
            $c->set($k, $v);
        }
        $c->call('submit')->assertSet('sent', true);
    }
    $c = Livewire::test(AnalysisRequest::class);
    foreach (analysisFields() as $k => $v) {
        $c->set($k, $v);
    }
    $c->call('submit')->assertSet('sent', false)->assertSee('fem bestillinger');

    expect(MetisAnalysisRequest::count())->toBe(5);
});
