<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\AlertDetail;

beforeEach(function () {
    session(['metis_user_token' => '13|fake-token-for-test']);
});

it('mounts with alert id and fetches alert via getAlert', function () {
    Http::fake([
        '*/v1/alerts/123' => Http::response([
            'data' => [
                'id' => 123,
                'title' => 'Nyt pantebrev tinglyst',
                'description' => 'Et nyt pantebrev er tinglyst på Bredgade 40, 1260 København K',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-001',
                    'change_type' => 'new',
                    'priority' => 'low',
                    'before' => null,
                    'after' => [
                        'principal_amount' => 250000000, // 2.500.000 kr (in ører)
                        'interest_rate' => 4.5,
                        'creditor' => 'Nordea Kredit',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                    'property_address' => 'Bredgade 40, 1260 København K',
                ],
                'watchlist' => [
                    'id' => 1,
                    'watch_type' => 'property',
                    'watch_value' => '12345',
                    'display_label' => 'Bredgade 40',
                ],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 123])
        ->assertSet('alertId', 123)
        ->assertSet('error', null)
        ->assertSet('loading', false)
        ->assertSee('Nyt pantebrev tinglyst')
        ->assertSee('Bredgade 40');
});

it('shows error when alert is not found (404)', function () {
    Http::fake([
        '*/v1/alerts/999' => Http::response(['message' => 'Not found'], 404),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 999])
        ->assertSet('alert', null)
        ->assertSee('Alert ikke fundet eller ingen adgang.');
});

it('renders 2-column diff for principal_change', function () {
    Http::fake([
        '*/v1/alerts/200' => Http::response([
            'data' => [
                'id' => 200,
                'title' => 'Hovedstol ændret',
                'description' => 'Hovedstol ændret fra 2.000.000 til 2.500.000',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-001',
                    'change_type' => 'principal_change',
                    'priority' => 'low',
                    'before' => [
                        'principal_amount' => 200000000,
                        'interest_rate' => 4.0,
                        'creditor' => 'Nordea Kredit',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                    'after' => [
                        'principal_amount' => 250000000,
                        'interest_rate' => 4.0,
                        'creditor' => 'Nordea Kredit',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                ],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 200])
        ->assertSet('error', null)
        ->assertSee('Hovedstol ændret')
        ->assertSee('Hovedstol')
        ->assertSee('Kreditor')
        ->assertSee('2.000.000') // before
        ->assertSee('2.500.000'); // after
});

it('shows only after-column for change_type=new', function () {
    Http::fake([
        '*/v1/alerts/300' => Http::response([
            'data' => [
                'id' => 300,
                'title' => 'Nyt pantebrev',
                'description' => 'Nyt pantebrev tinglyst',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-002',
                    'change_type' => 'new',
                    'priority' => 'low',
                    'before' => null,
                    'after' => [
                        'principal_amount' => 100000000,
                        'interest_rate' => 5.0,
                        'creditor' => 'Realkredit Danmark',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                ],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 300])
        ->assertSee('Ny tilstand')
        ->assertSee('1.000.000')
        ->assertSee('Realkredit Danmark');
});

it('shows only before-column for change_type=removed', function () {
    Http::fake([
        '*/v1/alerts/400' => Http::response([
            'data' => [
                'id' => 400,
                'title' => 'Pantebrev fjernet',
                'description' => 'Pantebrev fjernet',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-003',
                    'change_type' => 'removed',
                    'priority' => 'low',
                    'before' => [
                        'principal_amount' => 50000000,
                        'interest_rate' => 3.5,
                        'creditor' => 'Jyske Realkredit',
                        'mortgage_type' => 'realkredit',
                        'is_active' => true,
                    ],
                    'after' => null,
                ],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 400])
        ->assertSee('Fjernet')
        ->assertSee('500.000')
        ->assertSee('Jyske Realkredit');
});

it('renders high-priority badge for new_lien (udlæg)', function () {
    Http::fake([
        '*/v1/alerts/500' => Http::response([
            'data' => [
                'id' => 500,
                'title' => 'Nyt udlæg tinglyst',
                'description' => 'Et nyt udlæg er tinglyst',
                'is_read' => false,
                'priority' => 'high',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => [
                    'bfe' => '12345',
                    'mortgage_id' => 'M-004',
                    'change_type' => 'new_lien',
                    'priority' => 'high',
                    'before' => null,
                    'after' => [
                        'principal_amount' => 30000000,
                        'interest_rate' => 0,
                        'creditor' => 'SKAT',
                        'mortgage_type' => 'lien',
                        'is_active' => true,
                    ],
                ],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 500])
        ->assertSee('Nyt udlæg (høj prioritet)');
});

it('markRead calls API and refetches alert', function () {
    Http::fake([
        '*/v1/alerts/123' => Http::response([
            'data' => [
                'id' => 123,
                'title' => 'Test',
                'description' => 'Test alert',
                'is_read' => false,
                'priority' => 'low',
                'created_at' => '2026-05-04T10:00:00Z',
                'metadata' => ['bfe' => '12345', 'change_type' => 'new', 'before' => null, 'after' => null],
                'watchlist' => ['id' => 1, 'watch_type' => 'property', 'watch_value' => '12345'],
            ],
        ], 200),
        '*/v1/alerts/123/read' => Http::response(['data' => ['id' => 123, 'is_read' => true]], 200),
    ]);

    Livewire::test(AlertDetail::class, ['id' => 123])
        ->call('markRead');

    Http::assertSent(fn ($req) =>
        str_contains($req->url(), '/v1/alerts/123/read') && $req->method() === 'PATCH'
    );
});

/*
 * Live observer-path schema (DetectMortgageChange::safeMetadata).
 *
 * Production alerts come from the MortgageObserver → DetectMortgageChange job,
 * which writes a DIFFERENT metadata shape than the snapshot-path tests above:
 *   - key `change_kind` (new|amount_changed|rate_changed|paid_off), not `change_type`
 *   - flat `address`, `creditor`, `principal_amount_kr` (KRONER), `mortgage_type`,
 *     `registered_date` — no `before`/`after` objects, no `property_address`
 * The detail view must render these correctly, not collapse them to "new".
 */

/** Build a realistic observer-path alert payload as returned by GET /v1/alerts/{id}. */
function observerAlert(array $overrides = []): array
{
    return array_replace_recursive([
        'id' => 700,
        'title' => 'Rente ændret: Bredgade 40, 1260 København K',
        'description' => 'Ny rente: 5% (var 4%).',
        'is_read' => false,
        'priority' => 'low',
        'created_at' => '2026-06-21T10:00:00Z',
        'metadata' => [
            'mortgage_id' => 9001,
            'property_id' => 555,
            'address' => 'Bredgade 40, 1260 København K',
            'postal_code' => '1260',
            'mortgage_type' => 'realkreditpantebrev',
            'change_kind' => 'rate_changed',
            'principal_amount_kr' => 2500000,
            'interest_rate' => 5.0,
            'creditor' => 'Nordea Kredit',
            'registered_date' => '2026-06-20',
        ],
        'watchlist' => [
            'id' => 1, 'watch_type' => 'property', 'watch_value' => '555',
            'display_label' => 'Bredgade 40',
        ],
    ], $overrides);
}

it('renders a live rate_changed alert with correct label, address and creditor', function () {
    Http::fake(['*/v1/alerts/700' => Http::response(['data' => observerAlert()], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 700])
        ->assertSet('error', null)
        ->assertDontSee('Ukendt ejendom')   // address lives in metadata.address
        ->assertDontSee('Nyt pantebrev')    // it's a rate change, not a new mortgage
        ->assertSee('Rente ændret')
        ->assertSee('Nordea Kredit')        // flat metadata.creditor
        ->assertSee('5,00');                // interest_rate now surfaced in facts panel
});

it('renders a live amount_changed alert with hovedstol and creditor', function () {
    Http::fake(['*/v1/alerts/701' => Http::response(['data' => observerAlert([
        'id' => 701,
        'title' => 'Hovedstol ændret: Bredgade 40, 1260 København K',
        'description' => 'Ny hovedstol: 2.500.000 kr (var 2.000.000 kr).',
        'metadata' => ['change_kind' => 'amount_changed'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 701])
        ->assertSet('error', null)
        ->assertDontSee('Ukendt ejendom')
        ->assertDontSee('Nyt pantebrev')
        ->assertSee('Hovedstol ændret')
        ->assertSee('Nordea Kredit')
        ->assertSee('2.500.000');           // principal_amount_kr rendered as kr
});

it('renders a live new udlæg as high-priority new_lien', function () {
    Http::fake(['*/v1/alerts/702' => Http::response(['data' => observerAlert([
        'id' => 702,
        'title' => 'Nyt udlaeg: Bredgade 40, 1260 København K',
        'description' => 'Hovedstol: 30.000 kr. Kreditor: SKAT.',
        'priority' => 'high',
        'metadata' => ['change_kind' => 'new', 'mortgage_type' => 'udlaeg', 'creditor' => 'SKAT'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 702])
        ->assertSet('error', null)
        ->assertSee('Nyt udlæg (høj prioritet)')
        ->assertSee('SKAT');
});

it('renders a live paid_off alert as removed, not new', function () {
    Http::fake(['*/v1/alerts/703' => Http::response(['data' => observerAlert([
        'id' => 703,
        'title' => 'Pantebrev afløst: Bredgade 40, 1260 København K',
        'description' => 'Pantebrev er afløst (is_active=false).',
        'metadata' => ['change_kind' => 'paid_off'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 703])
        ->assertSet('error', null)
        ->assertDontSee('Ukendt ejendom')
        ->assertDontSee('Nyt pantebrev')
        ->assertSee('Pantebrev fjernet');
});

it('does not masquerade an unknown change_kind as a new mortgage', function () {
    // A future producer kind the view does not know about must fail visibly —
    // show title/description, never fabricate a confident "Nyt pantebrev" panel.
    Http::fake(['*/v1/alerts/704' => Http::response(['data' => observerAlert([
        'id' => 704,
        'title' => 'Kreditor skiftet: Bredgade 40, 1260 København K',
        'description' => 'Kreditor skiftet fra Nordea Kredit til Jyske Realkredit.',
        'metadata' => ['change_kind' => 'creditor_swapped'],
    ])], 200)]);

    Livewire::test(AlertDetail::class, ['id' => 704])
        ->assertSet('error', null)
        ->assertDontSee('Nyt pantebrev')
        ->assertDontSee('Ny tilstand')
        ->assertSee('Kreditor skiftet fra Nordea Kredit'); // description still surfaced
});
