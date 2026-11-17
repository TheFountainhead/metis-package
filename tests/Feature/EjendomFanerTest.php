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

    // De fem skjulte paneler har x-show + display:none (x-cloak virker ikke uden vaertens CSS).
    // Overblik er IKKE skjult server-side, saa et Alpine-nedbrud stadig viser den.
    expect(substr_count($html, 'role="tabpanel"'))->toBe(6)
        ->and(substr_count($html, 'role="tab"'))->toBe(6)
        ->and(preg_match_all('/<div role="tabpanel"[^>]*x-show="fane === \'\w+\'" style="display:none"/', $html))->toBe(5)
        ->and($html)->toMatch('/id="panel-overblik"[^>]*x-show="fane === \'overblik\'" tabindex="0"/')
        ->and($html)->not->toMatch('/id="panel-overblik"[^>]*display:none/')
        ->and(substr_count($html, 'wire:ignore.self'))->toBeGreaterThanOrEqual(7);
});

it('har alle tretten sektioner med, hver i sit panel', function () {
    $html = $this->get('/lookup/address/Roskildevej%2010%2C%202000%20Frederiksberg')->assertOk()->getContent();

    $paneler = [
        'overblik' => ['map-panel', 'address-valuation', 'address-skraafoto'],
        'bbr' => ['address-bbr', 'address-energy'],
        'ejerforhold' => ['address-owners', 'address-companies'],
        'tinglysning' => ['address-mortgages', 'address-transactions'],
        'handler' => ['address-similar-trades', 'address-comparison'],
        'omraade' => ['address-planning', 'address-heritage'],
    ];

    foreach ($paneler as $panel => $sektioner) {
        $start = strpos($html, 'id="panel-'.$panel.'"');
        $slut = strpos($html, 'role="tabpanel"', $start + 1) ?: strlen($html);
        $uddrag = substr($html, $start, $slut - $start);

        foreach ($sektioner as $sektion) {
            expect($uddrag)->toContain('metis-'.$sektion);
        }
    }
});

it('kan betjenes med tastaturet, og kortet maaler sig igen naar det vises', function () {
    $html = $this->get('/lookup/address/Roskildevej%2010%2C%202000%20Frederiksberg')->assertOk()->getContent();

    expect($html)
        ->toContain('@keydown.arrow-right.prevent="flyt(1)"')
        ->toContain(':tabindex="fane === \'bbr\' ? 0 : -1"')
        ->toContain('@hashchange.window="fraHash()"')
        ->toContain('@keydown.end.prevent="vaelg(faner[faner.length - 1], true)"');

    // Kortet maaler sig selv igen naar dets panel vises (Leaflet i en skjult container = 0x0).
    expect(file_get_contents(__DIR__.'/../../resources/views/livewire/map-panel.blade.php'))
        ->toContain('new ResizeObserver((poster) =>')
        ->toContain('if (r.width > 0 && r.height > 0) this.map.invalidateSize();')
        ->toContain('destroy() {');
});

it('har ingen faner paa et selskab', function () {
    $html = $this->get('/lookup/cvr/37792594')->assertOk()->getContent();

    expect($html)->not->toContain('role="tablist"');
});
