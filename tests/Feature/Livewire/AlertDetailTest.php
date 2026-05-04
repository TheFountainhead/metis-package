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
