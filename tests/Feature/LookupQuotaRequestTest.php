<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use TheFountainhead\Metis\Mail\QuotaRequestNotification;
use TheFountainhead\Metis\Models\MetisLead;

uses(RefreshDatabase::class);

/**
 * Kvote pr. testbruger + "bed om flere opslag" — Frederiks model 10/8.
 *
 * 🔑 Før denne ændring gav email-verifikation UBEGRÆNSET adgang. Nu får hver
 * testbruger en kvote og kan anmode om flere, så Frederik ser hvem der rent
 * faktisk bruger Metis før betaling besluttes.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    config()->set('metis.gating.free_lookups', 1);
    config()->set('metis.admin.notify_email', 'fred@frankston.io');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []])]);
    Mail::fake();
});

function testbruger(array $extra = []): MetisLead
{
    return MetisLead::create(array_merge([
        'email' => 'test@ktemadev.dk',
        'name' => 'David Loft',
        'company_name' => 'Ktema Development ApS',
        'lookup_count' => 0,
        'lookup_quota' => 5,
    ], $extra));
}

it('en verificeret bruger med kvote tilbage slipper igennem', function () {
    testbruger(['lookup_count' => 2, 'lookup_quota' => 5]);
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    $this->get('/lookup/cvr/37792594')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');
});

it('🚨 en verificeret bruger UDEN kvote tilbage gates', function () {
    // Kernen: verifikation er ikke laengere en doer der aabner helt.
    testbruger(['lookup_count' => 5, 'lookup_quota' => 5]);
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    $this->get('/lookup/cvr/37792594')
        ->assertOk()
        ->assertSee('Du har brugt dine gratis opslag');
});

/**
 * 🚨 MAALT 10/8: `lookup_count` paa lead'et blev ALDRIG taalt op ved et
 * opslag — kun ved verifikation. En kvote der laeser et felt ingen skriver
 * til ville aldrig blive opbrugt, og gaten ville se ud til at virke mens den
 * slap alt igennem. Samme fejlklasse som `lookups`-tabellen der laa ubrugt
 * siden marts.
 */
it('🚨 et opslag taeller op paa LEAD ET, ikke kun i sessionen', function () {
    $lead = testbruger(['lookup_count' => 0, 'lookup_quota' => 5]);
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    $this->get('/lookup/cvr/37792594')->assertOk();

    expect($lead->fresh()->lookup_count)->toBe(1);
});

it('kvoten bruges op opslag for opslag', function () {
    $lead = testbruger(['lookup_count' => 0, 'lookup_quota' => 2]);
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    $this->get('/lookup/cvr/37792594')->assertOk()->assertDontSee('Du har brugt');
    $this->get('/lookup/cvr/37792595')->assertOk()->assertDontSee('Du har brugt');
    $this->get('/lookup/cvr/37792596')->assertOk()->assertSee('Du har brugt');

    expect($lead->fresh()->lookup_count)->toBe(2);
});

it('🔑 anmodningen sender en besked til Frederik', function () {
    testbruger(['lookup_count' => 5, 'lookup_quota' => 5]);
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    Livewire::test('metis-email-gate')
        ->dispatch('show-email-gate')
        ->call('anmodOmFlereOpslag');

    Mail::assertSent(QuotaRequestNotification::class);
});

it('🪤 to klik sender KUN én besked', function () {
    // Et utaalmodigt klik maa ikke give Frederik fem ens mails.
    testbruger(['lookup_count' => 5, 'lookup_quota' => 5]);
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    $c = Livewire::test('metis-email-gate')->dispatch('show-email-gate');
    $c->call('anmodOmFlereOpslag');
    $c->call('anmodOmFlereOpslag');

    Mail::assertSentCount(1);
});

it('anmodningen gemmes som tidsstempel, saa den overlever cookie-rydning', function () {
    $lead = testbruger(['lookup_count' => 5, 'lookup_quota' => 5]);
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    Livewire::test('metis-email-gate')->dispatch('show-email-gate')->call('anmodOmFlereOpslag');

    expect($lead->fresh()->quota_requested_at)->not->toBeNull();
});

it('🔑 en verificeret bruger ser ANMODNINGS-trinnet, ikke mail-formularen', function () {
    // De har allerede givet deres mail. At bede om den igen ville vaere at
    // spoerge om noget vi ved.
    testbruger(['lookup_count' => 5, 'lookup_quota' => 5]);
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    Livewire::test('metis-email-gate')
        ->dispatch('show-email-gate')
        ->assertSet('step', 'quota');
});

it('en ANONYM bruger ser stadig mail-formularen', function () {
    Livewire::test('metis-email-gate')
        ->dispatch('show-email-gate')
        ->assertSet('step', 'email');
});

it('metis:grant-quota aabner for flere opslag', function () {
    $lead = testbruger(['lookup_count' => 5, 'lookup_quota' => 5, 'quota_requested_at' => now()]);

    $this->artisan('metis:grant-quota test@ktemadev.dk 25')->assertSuccessful();

    expect($lead->fresh()->lookup_quota)->toBe(25)
        ->and($lead->fresh()->quota_requested_at)->toBeNull();
});

it('🪤 grant-quota nulstiller anmodningen, saa brugeren kan spoerge igen', function () {
    // Uden nulstillingen ville UI'et blive ved med at vise "anmodning sendt",
    // og brugeren kunne aldrig bede om mere naeste gang de loeb toer.
    $lead = testbruger(['lookup_count' => 5, 'lookup_quota' => 5, 'quota_requested_at' => now()]);

    $this->artisan('metis:grant-quota test@ktemadev.dk 25')->assertSuccessful();
    $this->withSession(['metis_verified_email' => 'test@ktemadev.dk']);

    Livewire::test('metis-email-gate')
        ->dispatch('show-email-gate')
        ->assertSet('kvoteAnmodet', false);
});

it('grant-quota fejler tydeligt paa ukendt email', function () {
    $this->artisan('metis:grant-quota findes-ikke@example.com 25')->assertFailed();
});

it('🪤 en pilot-token gaar stadig fri af kvoten', function () {
    testbruger(['lookup_count' => 999, 'lookup_quota' => 5]);
    $this->withSession([
        'metis_verified_email' => 'test@ktemadev.dk',
        'metis_user_token' => 'pilot-abc',
    ]);

    $this->get('/lookup/cvr/37792594')
        ->assertOk()
        ->assertDontSee('Du har brugt dine gratis opslag');
});
