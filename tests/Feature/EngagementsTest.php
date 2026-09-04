<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\Engagement;
use TheFountainhead\Metis\Livewire\Engagements;

/**
 * Långiverens cockpit i UI'et. Siden viser KUN brugerens egne engagementer;
 * hvilket selskab det er, afgør registry-api. Intet CVR sendes herfra.
 */
function cockpitEngagement(array $overrides = []): array
{
    return array_replace_recursive([
        'key' => 'c22222222',
        'owners' => [['key' => 'c22222222', 'type' => 'company', 'cvr' => '22222222', 'name' => 'EJENDOMSSELSKABET TORVET ApS', 'status' => 'NORMAL', 'label' => 'EJENDOMSSELSKABET TORVET ApS']],
        'lender_kr' => 25_000_000,
        'documents' => 1,
        'total_debt_kr' => 72_200_000,
        'worst_ahead_kr' => 45_000_000,
        'latest_change_at' => '2026-06-01',
        'has_changes_since_own' => true,
        'data_quality' => [],
        'properties' => [[
            'id' => 1, 'bfe' => '900100', 'address' => 'Torvet 2', 'postal_code' => '3520', 'city' => 'Farum',
            'own_since' => '2026-02-23', 'ahead_kr' => 45_000_000, 'total_debt_kr' => 72_200_000,
            'ladder' => [
                ['id' => 11, 'priority' => 10, 'type' => 'ejerpantebrev', 'creditor' => 'EJENDOMSSELSKABET TORVET ApS', 'pledgees' => ['Bank Foran A/S'], 'amount_kr' => 45_000_000, 'registered_at' => '2026-03-17', 'is_own' => false, 'is_ahead' => true, 'is_pari' => false, 'priority_unknown' => false],
                ['id' => 12, 'priority' => 12, 'type' => 'ejerpantebrev', 'creditor' => 'EJENDOMSSELSKABET TORVET ApS', 'pledgees' => ['Långiver A/S', 'Medlångiver ApS'], 'amount_kr' => 25_000_000, 'registered_at' => '2026-02-23', 'is_own' => true, 'is_ahead' => false, 'is_pari' => false, 'priority_unknown' => false],
                ['id' => 13, 'priority' => 15, 'type' => 'privatPantebrev', 'creditor' => 'Privat Invest P/S', 'pledgees' => [], 'amount_kr' => 700_000, 'registered_at' => '2026-06-01', 'is_own' => false, 'is_ahead' => false, 'is_pari' => false, 'priority_unknown' => false],
            ],
        ]],
        'changes' => [['kind' => 'new_lien', 'date' => '2026-06-01', 'amount_kr' => 700_000, 'type' => 'privatPantebrev', 'creditor' => 'Privat Invest P/S', 'is_ahead' => false, 'severity' => 'low', 'property_id' => 1, 'address' => 'Torvet 2']],
    ], $overrides);
}

function cockpitMeta(): array
{
    return [
        'lender' => ['cvr' => '11111111', 'name' => 'LÅNGIVER A/S'],
        'measured_at' => '2026-09-04T06:00:00+00:00',
        'totals' => ['lender_kr' => 25_000_000, 'documents' => 1, 'properties' => 1, 'engagements' => 1],
        'disclaimer' => 'Beløbet er hvad långiveren står anført for i Tinglysningen. En panthaver kan optræde på vegne af andre kreditorer.',
        'source' => 'Tinglysningen (§ 1 a, stk. 1)',
    ];
}

beforeEach(function () {
    Http::preventStrayRequests();
});

it('uden pilot-token vises "kun for pilotbrugere", aldrig en tom liste, og API-et kaldes ikke', function () {
    Http::fake();

    Livewire::test(Engagements::class)
        ->assertSee('Kun for pilotbrugere')
        ->assertDontSee('Ingen tinglyst pant');

    Http::assertNothingSent();
});

it('viser engagementerne med totaler, forbehold og måletidspunkt', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements' => Http::response(['data' => [cockpitEngagement()], 'meta' => cockpitMeta()])]);

    Livewire::test(Engagements::class)
        ->assertSee('Långiver A/S')
        ->assertSee('25.000.000')
        ->assertSee('Ejendomsselskabet Torvet ApS')
        ->assertSee('45.000.000')
        ->assertSee('på vegne af andre kreditorer')
        ->assertSee('04.09.2026');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/engagements') && $r->hasHeader('Authorization', 'Bearer 19|abc'));
});

it('viser forbeholdet selv når API-et ikke sender det', function () {
    session(['metis_user_token' => '19|abc']);
    $meta = cockpitMeta();
    unset($meta['disclaimer']);
    Http::fake(['*/v1/engagements' => Http::response(['data' => [cockpitEngagement()], 'meta' => $meta])]);

    Livewire::test(Engagements::class)->assertSee('på vegne af andre kreditorer');
});

it('403 fra API-et betyder "ikke knyttet til et långiverselskab", ikke en tom liste', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements' => Http::response(['message' => 'This action is unauthorized.'], 403)]);

    Livewire::test(Engagements::class)
        ->assertSet('unbound', true)
        ->assertSee('ikke knyttet til et långiverselskab')
        ->assertDontSee('Ingen tinglyst pant');
});

it('andre fejl vises som "kunne ikke hentes" med prøv igen', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements' => Http::response('', 500)]);

    Livewire::test(Engagements::class)
        ->assertSet('rows', null)
        ->assertSee('Kunne ikke hente engagementer');
});

it('nul engagementer er et gyldigt svar med långivernavn og dato', function () {
    session(['metis_user_token' => '19|abc']);
    $meta = cockpitMeta();
    $meta['totals'] = ['lender_kr' => 0, 'documents' => 0, 'properties' => 0, 'engagements' => 0];
    Http::fake(['*/v1/engagements' => Http::response(['data' => [], 'meta' => $meta])]);

    Livewire::test(Engagements::class)
        ->assertSet('errorMessage', null)
        ->assertSee('Ingen tinglyst pant registreret for Långiver A/S');
});

it('samejet engagement vises med begge ejere, personejet som privatperson', function () {
    session(['metis_user_token' => '19|abc']);
    $shared = cockpitEngagement(['key' => 'c22222222+c33333333', 'owners' => [
        ['key' => 'c22222222', 'type' => 'company', 'cvr' => '22222222', 'name' => 'A ApS', 'status' => null, 'label' => 'A ApS'],
        ['key' => 'c33333333', 'type' => 'company', 'cvr' => '33333333', 'name' => 'B ApS', 'status' => null, 'label' => 'B ApS'],
    ]]);
    $person = cockpitEngagement(['key' => 'p77', 'owners' => [['key' => 'p77', 'type' => 'person', 'person_id' => 77, 'label' => 'Privatperson']]]);
    Http::fake(['*/v1/engagements' => Http::response(['data' => [$shared, $person], 'meta' => cockpitMeta()])]);

    Livewire::test(Engagements::class)
        ->assertSee('A ApS + B ApS')
        ->assertSee('Privatperson');
});

it('engagementssiden viser stigen med Mig, Foran mig og ændringerne', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements/*' => Http::response(['data' => cockpitEngagement(), 'meta' => cockpitMeta()])]);

    Livewire::test(Engagement::class, ['key' => 'c22222222'])
        ->assertSee('Torvet 2')
        ->assertSee('Foran mig')
        ->assertSee('Mig')
        ->assertSee('Bank Foran A/S')
        ->assertSee('Ny hæftelse')
        ->assertSee('Privat Invest P/S')
        ->assertSee('23.02.2026');

    Http::assertSent(fn ($r) => str_ends_with($r->url(), '/v1/engagements/c22222222'));
});

it('engagementssiden uden ændringer siger det med dato, ikke en tom boks', function () {
    session(['metis_user_token' => '19|abc']);
    // array_replace_recursive kan ikke TØMME en liste; sæt den eksplicit.
    $e = cockpitEngagement(['has_changes_since_own' => false, 'latest_change_at' => null]);
    $e['changes'] = [];
    Http::fake(['*/v1/engagements/*' => Http::response(['data' => $e, 'meta' => cockpitMeta()])]);

    Livewire::test(Engagement::class, ['key' => 'c22222222'])
        ->assertSee('Ingen ændringer siden 23.02.2026');
});

it('404 på et engagement giver "findes ikke blandt dine", ikke en fejl', function () {
    session(['metis_user_token' => '19|abc']);
    Http::fake(['*/v1/engagements/*' => Http::response(['message' => 'Not found.'], 404)]);

    Livewire::test(Engagement::class, ['key' => 'c99999999'])
        ->assertSet('notFound', true)
        ->assertSee('findes ikke blandt dine engagementer');
});

it('/laangiver redirecter til /engagementer, og det frie CVR-opslag er væk', function () {
    $this->get('/laangiver?cvr=35050027')->assertRedirect('/engagementer');
    expect(class_exists(\TheFountainhead\Metis\Livewire\LenderExposure::class))->toBeFalse();
});

it('ukendt prioritet vises som ukendt, aldrig som 0 kr. foran mig', function () {
    session(['metis_user_token' => '19|abc']);
    $e = cockpitEngagement(['worst_ahead_kr' => null, 'data_quality' => ['own_priority_unknown']]);
    $e['properties'][0]['ahead_kr'] = null;
    Http::fake(['*/v1/engagements/*' => Http::response(['data' => $e, 'meta' => cockpitMeta()]), '*/v1/engagements' => Http::response(['data' => [$e], 'meta' => cockpitMeta()])]);

    Livewire::test(Engagement::class, ['key' => 'c22222222'])->assertSee('ukendt (prioritet mangler)');
    Livewire::test(Engagements::class)->assertSee('ukendt');
});
