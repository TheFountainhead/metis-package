<?php

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Opfoelgning paa PR #192, review M5: en verificeret bruger kunne hente
 * vilkaarlige selskabers struktur og ejendomme uden at bruge af sin
 * lead-kvote.
 *
 * `MetisSection::$query` er laast siden fix-runde 1, saa selve opslaget kan
 * ikke skiftes. Men PersonStructures arbejdskoeer `structureByCompany` og
 * `propertiesByCompany` er offentlige og ulaaste, og `tick()` henter for hver
 * noegle med status 'pending'. Over `/livewire/update` kunne en klient
 * skrive `structureByCompany.99999999 = pending` og faa serveren til at
 * hente selskab 99999999's struktur og portefoelje paa tenant-noeglen, uden
 * et talt opslag.
 *
 * Maalt 6/10 (foer rettelsen): det hentede naaede IKKE klienten. `rebuild()`
 * bygger kun noder fra personens egne selskaber, og CVR'et forekom 0 gange
 * i svaret. Hullet var altsaa et ugaetet, utalt udgaaende kald, ikke en
 * datalaek. Testene maaler derfor de udgaaende kald.
 *
 * Rettelsen er ikke `#[Locked]` (se PersonStructure:200 om hvorfor Locked
 * braekker den lazy rundtur), men en serverside-kontrol: koeerne beskaeres
 * til de selskaber personens egen selskabsliste (hentet paa den laaste
 * `query`) faktisk indeholder. Navigation i grafen roeres ikke: alle
 * legitime noegler kommer fra den liste.
 */
// CPR-opslag er lukket (9/10-2026); rundturen koerer nu over personsiden
// (navnetilstand), som har samme arbejdskoeer og samme beskaering.
const KM_NAVN = 'Lars Larsen';
const KM_EGEN = '11111111';
const KM_FREMMED = '99999999';

beforeEach(function () {
    $this->withoutVite();
    config()->set('metis.mode', 'standalone');
    config()->set('metis.gating.enabled', true);
    Cache::flush();
    Http::preventStrayRequests();
    Http::fake([
        '*/v1/cvr/person-companies-by-name*' => Http::response(['data' => ['companies' => [[
            'cvr' => KM_EGEN, 'name' => 'EGEN ApS', 'is_active' => true, 'has_direct_ownership' => true,
            'roles' => [['is_current' => true, 'title' => 'Ejer', 'ownership_share' => 100]],
        ]]]]),
        '*/v1/cvr/cross-ownership*' => Http::response(['data' => ['relationships' => []]]),
        '*/v1/cvr/company-structure*' => Http::response(['data' => ['subsidiaries' => [['cvr' => '55555555', 'name' => 'DATTER ApS']]]]),
        '*/property-portfolio*' => Http::response(['data' => ['portfolio' => ['properties' => [], 'property_count' => 0]]]),
        '*' => Http::response(['data' => []]),
    ]);
    $this->withServerVariables(['REMOTE_ADDR' => '203.0.113.99']);
    $this->withSession(['metis_verified_email' => 'kunde@firma.dk']);
});

function kmLazy(string $html): array
{
    preg_match_all('/<[a-z]+\s[^>]*wire:snapshot="[^"]*"[^>]*>/i', $html, $tags);

    foreach ($tags[0] as $tag) {
        if (preg_match('/wire:snapshot="([^"]*)"/', $tag, $s)
            && preg_match("/__lazyLoad\\(&#039;([^&]+)&#039;\\)|__lazyLoad\\('([^']+)'\\)/", $tag, $l)) {
            $snapshot = html_entity_decode($s[1], ENT_QUOTES);

            if ((json_decode($snapshot, true)['memo']['name'] ?? null) === 'metis-person-structure') {
                return [$snapshot, $l[1] !== '' ? $l[1] : $l[2]];
            }
        }
    }

    throw new RuntimeException('Ingen metis-person-structure paa siden');
}

function kmKald(object $test, string $snapshot, string $metode, array $params = [], array $updates = [])
{
    return $test->withHeaders(['X-Livewire' => 'true'])->postJson('/livewire/update', [
        'components' => [[
            'snapshot' => $snapshot,
            'updates' => (object) $updates,
            'calls' => [['path' => '', 'method' => $metode, 'params' => $params]],
        ]],
    ]);
}

/** Rigtig rundtur: personsiden, derefter sektionens `__lazyLoad`. Returnerer det loadede snapshot. */
function kmLoadetStruktur(object $test): string
{
    [$snapshot, $encoded] = kmLazy($test->get('/lookup/person/'.KM_NAVN)->assertOk()->getContent());

    $svar = kmKald($test, $snapshot, '__lazyLoad', [$encoded])->assertOk();
    $loadet = $svar->json('components.0.snapshot');

    expect(json_decode($loadet, true)['data']['skeletonStatus'] ?? null)->toBe('loaded');

    return $loadet;
}

function kmStrukturFor(string $cvr): Closure
{
    return fn ($r) => str_contains($r->url(), '/v1/cvr/company-structure') && (($r->data()['cvr'] ?? null) === $cvr);
}

it('🚨 M5: en manipuleret structureByCompany-noegle henter IKKE et fremmed selskabs struktur', function () {
    $loadet = kmLoadetStruktur($this);

    kmKald($this, $loadet, 'tick', [], ['structureByCompany.'.KM_FREMMED => 'pending'])->assertOk();

    Http::assertNotSent(kmStrukturFor(KM_FREMMED));
    // Positiv kontrol: samme tick henter personens EGET selskab.
    Http::assertSent(kmStrukturFor(KM_EGEN));
});

it('🚨 M5: en manipuleret propertiesByCompany-noegle henter IKKE et fremmed selskabs portefoelje', function () {
    $loadet = kmLoadetStruktur($this);

    // Fase 2 afsluttes foerst, saa tick() naar til fase 3.
    $svar = kmKald($this, $loadet, 'tick')->assertOk();
    $loadet = $svar->json('components.0.snapshot');

    kmKald($this, $loadet, 'tick', [], ['propertiesByCompany.'.KM_FREMMED => 'pending'])->assertOk();

    Http::assertNotSent(fn ($r) => str_contains($r->url(), '/v1/company/'.KM_FREMMED.'/property-portfolio'));
    Http::assertSent(fn ($r) => str_contains($r->url(), '/v1/company/'.KM_EGEN.'/property-portfolio'));
});

it('🚨 M5: retryStructures paa en fremmed "failed"-noegle henter heller intet', function () {
    $loadet = kmLoadetStruktur($this);

    $svar = kmKald($this, $loadet, 'retryStructures', [], ['structureByCompany.'.KM_FREMMED => 'failed'])->assertOk();
    kmKald($this, $svar->json('components.0.snapshot'), 'tick')->assertOk();

    Http::assertNotSent(kmStrukturFor(KM_FREMMED));
});

it('vagt: en fremmed noegle sat til "loaded" giver ikke det fremmede selskab fra CACHEN', function () {
    // `recoverStructureResults()` laeser 'loaded'-noegler fra cachen. Et
    // andet besoeg kan have varmet den for et vilkaarligt CVR.
    // 🪤 Groen ogsaa FOER rettelsen (`rebuild()` smider noeglen igen), saa den
    // beviser ikke beskaeringen; den vogter at det cachede aldrig vises.
    Cache::put('metis:company_structure:'.KM_FREMMED, ['subsidiaries' => [['cvr' => '77777777', 'name' => 'FREMMED DATTER ApS']]], 3600);
    $loadet = kmLoadetStruktur($this);

    $svar = kmKald($this, $loadet, 'tick', [], ['structureByCompany.'.KM_FREMMED => 'loaded'])->assertOk();

    expect((string) $svar->json('components.0.effects.html'))->not->toContain('FREMMED DATTER');
    expect(json_decode($svar->json('components.0.snapshot'), true)['data']['structureByCompany'][0] ?? [])
        ->not->toHaveKey(KM_FREMMED);
});

it('modstykke: navigation i grafen (expandNode + tick) virker som foer', function () {
    $loadet = kmLoadetStruktur($this);

    $svar = kmKald($this, $loadet, 'tick')->assertOk();
    $svar = kmKald($this, $svar->json('components.0.snapshot'), 'expandNode', ['sub:'.KM_EGEN])->assertOk();

    expect(json_decode($svar->json('components.0.snapshot'), true)['data']['structureByCompany'][0] ?? [])
        ->toHaveKey(KM_EGEN);
    expect((string) $svar->json('components.0.effects.html'))->toContain('DATTER ApS');
});

