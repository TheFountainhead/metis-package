<?php

use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use TheFountainhead\Metis\Livewire\AlertDetail;
use TheFountainhead\Metis\Livewire\AlertsInbox;

/**
 * En besked fra netværksovervågningen (registry-api `metadata.via_netvaerk`) skal sige, hvorfor den kom:
 * ejendommen ligger i en låntagers koncern, og brugeren har typisk ikke selv pant der.
 */
beforeEach(function () {
    $this->withoutVite();
    Http::preventStrayRequests();
    config()->set('metis.mode', 'standalone');
    session()->start();
    session(['metis_user_token' => '13|test', 'metis_pilot_verificeret' => true]);
});

function netvaerksAlert(array $o = []): array
{
    return $o + [
        'id' => 11, 'title' => 'Nyt udlaeg: Testvej 1', 'description' => 'Hovedstol: 100 kr.', 'is_read' => false, 'priority' => 'high',
        'created_at' => '2026-11-18T10:00:00Z',
        'metadata' => ['address' => 'Testvej 1', 'change_kind' => 'new', 'via_netvaerk' => ['top_cvr' => '10000001', 'top_navn' => 'Top Holding ApS']],
        'watchlist' => ['id' => 5, 'watch_type' => 'company', 'watch_value' => '10000001', 'display_label' => 'Netværk: Top Holding ApS', 'origin' => 'lender_network'],
    ];
}

function almindeligAlert(): array
{
    return ['id' => 12, 'title' => 'Hovedstol ændret: Andenvej 2', 'description' => 'x', 'is_read' => false, 'priority' => 'low', 'created_at' => '2026-11-18T10:00:00Z',
        'metadata' => ['address' => 'Andenvej 2', 'change_kind' => 'amount_changed'],
        'watchlist' => ['id' => 6, 'watch_type' => 'company', 'watch_value' => '20000002', 'display_label' => 'Låntager ApS']];
}

it('indbakken mærker en netværksbesked med toppen, og kun den', function () {
    Http::fake([
        '*/v1/alerts*' => Http::response(['data' => [netvaerksAlert(), almindeligAlert()]]),
        '*' => Http::response(['data' => []]),
    ]);

    $html = Livewire::test(AlertsInbox::class)->call('fetch')->html();

    expect(substr_count($html, 'data-via-netvaerk'))->toBe(1)
        ->and($html)->toContain('Via netværk: Top Holding ApS')
        ->toContain('koncerntop for en af dine låntagere')
        ->toContain('Via: Selskab Låntager ApS')
        ->not->toContain('Via: Selskab Netværk: Top Holding ApS');
});

it('detaljesiden forklarer hvorfor beskeden kom, med link til toppen', function () {
    Http::fake(['*/v1/alerts/11' => Http::response(['data' => netvaerksAlert()])]);

    Livewire::test(AlertDetail::class, ['id' => 11])
        ->assertSeeHtml('data-via-netvaerk')
        ->assertSee('Ejendommen ligger i koncernen under')->assertSee('Top Holding ApS')
        ->assertSee('ikke nødvendigvis har pant i ejendommen')
        ->assertSeeHtml('/lookup/cvr/10000001');
});

it('en almindelig besked får ingen forklaring om netværk', function () {
    Http::fake(['*/v1/alerts/12' => Http::response(['data' => almindeligAlert()])]);

    Livewire::test(AlertDetail::class, ['id' => 12])->assertDontSeeHtml('data-via-netvaerk')->assertDontSee('Via netværk');
});

it('🚨 tåler skæve data: kun et gyldigt CVR-nummer giver mærket, og navnet vises som tekst', function (mixed $via, bool $maerket) {
    $alert = netvaerksAlert();
    $alert['metadata']['via_netvaerk'] = $via;
    Http::fake(['*/v1/alerts/11' => Http::response(['data' => $alert])]);

    $c = Livewire::test(AlertDetail::class, ['id' => 11])->assertSet('error', null);
    $maerket ? $c->assertSeeHtml('data-via-netvaerk') : $c->assertDontSeeHtml('data-via-netvaerk');
    $c->assertDontSeeHtml('<script>alert(1)</script>');
})->with([
    'gyldig' => [['top_cvr' => '10000001', 'top_navn' => 'Top Holding ApS'], true],
    'cvr som tal' => [['top_cvr' => 10000001, 'top_navn' => 'Top Holding ApS'], true],
    'uden navn vises CVR' => [['top_cvr' => '10000001'], true],
    'navn med HTML' => [['top_cvr' => '10000001', 'top_navn' => '<script>alert(1)</script>'], true],
    'cvr med 7 cifre' => [['top_cvr' => '1000001', 'top_navn' => 'X'], false],
    'cvr med script' => [['top_cvr' => 'javascript:alert(1)', 'top_navn' => 'X'], false],
    'streng i stedet for struktur' => ['ja', false],
    'tom' => [[], false],
    'null' => [null, false],
    'navn som liste' => [['top_cvr' => '10000001', 'top_navn' => ['x']], true],
]);

it('metadata som JSON-streng giver også mærket', function () {
    $alert = netvaerksAlert();
    $alert['metadata'] = json_encode($alert['metadata']);
    Http::fake(['*/v1/alerts/11' => Http::response(['data' => $alert])]);

    Livewire::test(AlertDetail::class, ['id' => 11])->assertSeeHtml('data-via-netvaerk');
});
