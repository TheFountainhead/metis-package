<?php

use Illuminate\Support\Facades\Http;

/**
 * Resultatsider maa ikke indekseres.
 *
 * 🚨 MAALT PAA PROD 6/8: laangiver-siden (`/laangiver?cvr=...`) havde
 * HVERKEN et noindex-tag ELLER daekning i robots.txt. `robots.txt` havde
 * `Disallow: /*?q=`, som kun rammer soegeresultater — laangiver-siden bruger
 * `?cvr=`.
 *
 * Den side viser navngivne bankers kreditrisiko med adresser og beloeb.
 * Kilderne er offentlige (tinglysningslovens § 1 a, stk. 1), men et
 * Google-indeks er en NY UDGIVELSE: det goer et opslag man skal foretage til
 * noget der dukker op af sig selv.
 *
 * 🪤 HVORFOR DET IKKE BLEV OPDAGET: host-appen HAR et noindex i sin egen
 * layout.blade.php. Men `grep` finder nul konsumenter af den fil — den er
 * doed kode. Den layout der faktisk serverer prod er pakkens
 * standalone.blade.php. Beskyttelsen laa ét sted hvor den ikke virkede,
 * hvilket laeser som daekning i en hurtig gennemgang.
 *
 * 🔑 Testen rammer derfor RUTEN i standalone-mode — samme vej som prod —
 * ikke layoutfilen direkte.
 */
beforeEach(function () {
    // 🪤 Uden dette kaster standalone-layoutet "Vite manifest not found"
    // under Testbench, og HVER assertion nedenfor bliver et 500 — inklusive
    // `assertDontSee('noindex')`, som ville have bestaaet paa en fejlside.
    // En groen test af den art beviser intet.
    $this->withoutVite();

    config()->set('metis.mode', 'standalone');
    Http::preventStrayRequests();
    Http::fake(['*' => Http::response(['data' => []])]);
});

it('🚨 laangiver-siden med ?cvr= baerer noindex', function () {
    // Den konkrete side der var udaekket i prod.
    $this->get('/laangiver?cvr=35050027')
        ->assertOk()
        ->assertSee('name="robots"', false)
        ->assertSee('noindex', false);
});

it('forsiden UDEN parametre indekseres stadig', function () {
    // 🪤 Uden denne ville `noindex` paa alt bestaa testen ovenfor — og
    // afindeksere hele sitet. Forsiden er vores markedsfoering.
    $this->get('/')
        ->assertOk()
        ->assertDontSee('noindex', false);
});

it('enhver query-parameter udloeser noindex, ikke kun de kendte navne', function () {
    // 🔑 Gaten er `! empty(request()->query())`, ikke en liste af navne.
    // En liste skulle holdes synkron med hver ny side — praecis den slags
    // vedligehold der fejlede her: `?q=` var daekket, `?cvr=` var ikke.
    foreach (['/?q=56811913', '/laangiver?cvr=35050027'] as $url) {
        $this->get($url)->assertSee('noindex', false);
    }
});
