<?php

use Illuminate\Support\Facades\Http;

/**
 * Ejendomssiden har faner, saa man kan hoppe mellem sektionerne.
 *
 * 🔑 Fanerne maa IKKE aendre hvad der hentes: alle sektioner skal stadig vaere i siden
 * (lazy="on-load"), kun skjult med x-show. Ellers ville en fane der aldrig aabnes aldrig
 * hente sin data, og dem der staar paa en anden fane end Overblik faar tomme felter.
 */
beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', false);
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []])]);
});

it('viser seks faner med tilhoerende paneler paa en adresse', function () {
    $html = $this->get('/lookup/address/Roskildevej%2010%2C%202000%20Frederiksberg')->assertOk()->getContent();

    foreach (['overblik', 'bbr', 'ejerforhold', 'tinglysning', 'handler', 'omraade'] as $fane) {
        expect($html)
            ->toContain('id="fane-'.$fane.'"')
            ->toContain('aria-controls="panel-'.$fane.'"')
            ->toContain('id="panel-'.$fane.'"');
    }

    // Hvert panel er skjult med x-show + display:none (x-cloak virker ikke uden vaertens CSS).
    expect(substr_count($html, 'role="tabpanel"'))->toBe(6)
        ->and(substr_count($html, 'role="tab"'))->toBe(6)
        ->and(preg_match_all('/<div role="tabpanel"[^>]*x-show="fane === \'\w+\'" style="display:none"/', $html))->toBe(6);
});

it('har alle tretten sektioner med, ogsaa dem paa skjulte faner', function () {
    $html = $this->get('/lookup/address/Roskildevej%2010%2C%202000%20Frederiksberg')->assertOk()->getContent();

    foreach ([
        'map-panel', 'address-valuation', 'address-skraafoto', 'address-bbr', 'address-energy',
        'address-owners', 'address-companies', 'address-mortgages', 'address-transactions',
        'address-similar-trades', 'address-comparison', 'address-planning', 'address-heritage',
    ] as $sektion) {
        expect($html)->toContain('metis-'.$sektion);
    }
});

it('kan betjenes med tastaturet og fortaeller kortet at fanen er skiftet', function () {
    $html = $this->get('/lookup/address/Roskildevej%2010%2C%202000%20Frederiksberg')->assertOk()->getContent();

    expect($html)
        ->toContain('@keydown.arrow-right.prevent="flyt(1)"')
        ->toContain(':tabindex="fane === \'bbr\' ? 0 : -1"')
        ->toContain('@hashchange.window="fraHash()"')
        ->toContain("new CustomEvent('metis-fane'");

    // Kortet lytter paa samme begivenhed (Leaflet maa maale sig igen naar panelet vises).
    expect(file_get_contents(__DIR__.'/../../resources/views/livewire/map-panel.blade.php'))
        ->toContain('@metis-fane.window="map && map.invalidateSize()"');
});

it('har ingen faner paa et selskab', function () {
    $html = $this->get('/lookup/cvr/37792594')->assertOk()->getContent();

    expect($html)->not->toContain('role="tablist"');
});
