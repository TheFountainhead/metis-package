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

it('🚨 engagement-siden baerer noindex, og den gamle laangiver-adresse redirecter', function () {
    // Den konkrete side der var udaekket i prod var /laangiver?cvr=; den er
    // nedlagt (§ 50 c) og redirecter. Efterfoelgeren skal baere samme vaern.
    $this->get('/laangiver?cvr=35050027')->assertRedirect('/engagementer');

    $this->get('/engagementer?fra=link')
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
    foreach (['/?q=56811913', '/engagementer?fra=link'] as $url) {
        $this->get($url)->assertSee('noindex', false);
    }
});

/**
 * 🚨 MAALT PAA PROD 9/8: `/lookup/cpr/<personnummer>` svarede 200 til en
 * anonym klient UDEN noindex. Googlebot havde hentet 35 unikke CPR-URL'er,
 * 269 requests, alle 200.
 *
 * 🪤 HVORFOR TESTEN OVENFOR VAR GROEN IMENS: hver eneste case brugte en
 * query-parameter (`?cvr=`, `?q=`). Gaten var `! empty(request()->query())`,
 * saa testen bekraeftede praecis den mekanisme forfatteren havde bygget — og
 * spurgte aldrig hvilke sider der baerer persondata. `/lookup/{type}/{query}`
 * baerer opslaget i STIEN og har ingen query-streng.
 *
 * Verificeret paa prod: samme URL med `?x=1` tilfoejet FIK tagget. Det
 * isolerede aarsagen til query-betingelsen alene.
 */
it('🚨 CPR-opslag i STIEN baerer noindex — uden query-streng', function () {
    $this->get('/lookup/cpr/4006033395')
        ->assertOk()
        ->assertSee('name="robots"', false)
        ->assertSee('noindex', false);
});

it('🚨 alle opslagstyper i stien baerer noindex', function () {
    // 🪤 Ikke kun cpr. Hver type viser navngivne personer eller selskaber.
    foreach (['cvr/37792594', 'person/Frederik', 'address/Solhegnet 26'] as $sti) {
        $this->get('/lookup/'.$sti)
            ->assertOk()
            ->assertSee('noindex', false);
    }
});

it('🚨 X-Robots-Tag-headeren saettes — den daekker Livewire-svar', function () {
    // 🔑 Meta-tagget virker kun i HTML. Livewire-svar er JSON og gaar uden om
    // layoutet — og det er DEM der baerer dataene: den initiale HTML viser kun
    // "Henter data"-pladsholdere. Maalt 9/8: 20.549 af Googlebots requests var
    // POST. Et meta-tag alene ville altsaa daekke den tomme skal, ikke
    // indholdet.
    $this->get('/lookup/cpr/4006033395')
        ->assertOk()
        ->assertHeader('X-Robots-Tag', 'noindex, nofollow');
});

it('forsiden faar HVERKEN meta-noindex eller header', function () {
    // 🪤 Modstykket til ovenstaaende. Uden denne ville "noindex paa alt"
    // bestaa hver assertion — og afindeksere vores markedsfoering.
    $svar = $this->get('/')->assertOk();

    $svar->assertDontSee('noindex', false);

    expect($svar->headers->has('X-Robots-Tag'))->toBeFalse();
});

it('🚨 robots.txt fra pakken daekker BAADE admin og soegeresultater', function () {
    // 🚨 Der er TO robots.txt. Denne rute gav kun `Disallow: /admin`, mens
    // host-appens statiske `public/robots.txt` havde `Disallow: /*?q=`.
    // nginx serverer statiske filer FOER PHP, saa filen vandt i prod og
    // ruten vandt i tests — en test af opslags-beskyttelsen saa altsaa den
    // SVAGESTE af de to.
    //
    // 🪤 Forsvandt host-filen i et deploy, ville beskyttelsen gaa lydloest
    // tabt OG testen stadig vaere groen. Pakken baerer nu selv det fulde
    // saet.
    $body = $this->get('/robots.txt')->assertOk()->getContent();

    expect($body)->toContain('Disallow: /admin')
        ->and($body)->toContain('Disallow: /*?q=');
});
