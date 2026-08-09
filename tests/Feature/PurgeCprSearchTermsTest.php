<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Migrationen der fjerner personnumre fra soegehistorikken.
 *
 * 🚨 MAALT PAA PROD 9/8: seks raekker med `search_type='cpr'` baerer et
 * personnummer i `search_term`. Frederik har bekraeftet at nummeret er
 * testdata.
 *
 * 🔑 DEN VIGTIGE TEST ER IKKE AT CPR FJERNES — det er at CVR-numre BLIVER.
 * Den sideloebende session maalte 9/8 at `^\d{6}-?\d{4}$` gav 12 traf, hvoraf
 * 6 var enhedsnumre der begyndte med "40" (en dag der ikke findes). Uden
 * datovalidering ville oprydningen slette legitim soegehistorik.
 */
function indsætLookup(string $type, ?string $term): int
{
    return DB::table('metis_lookups')->insertGetId([
        'session_id' => 'sess-'.uniqid(),
        'search_type' => $type,
        'search_term' => $term,
        'ip_address' => '10.0.0.1',
        'is_cross_reference' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function koerPurge(): void
{
    // 🪤 Migrationen er allerede koert af RefreshDatabase — men paa en TOM
    // tabel, saa den naaede intet. Her koeres `up()` igen mod de raekker
    // testen selv har indsat. `require` af en anonym klasse returnerer
    // instansen, saa den kan kaldes direkte.
    (require __DIR__.'/../../database/migrations/2026_08_09_200000_purge_cpr_search_terms_from_metis_lookups.php')->up();
}

it('🚨 fjerner search_term fra en cpr-raekke', function () {
    $id = indsætLookup('cpr', '311278-1234');

    koerPurge();

    expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))->toBe('[fjernet: personnummer]');
});

it('🔑 beholder RAEKKEN — kun nummeret fjernes', function () {
    // search_type og tidspunkt er brugbar statistik og siger intet om hvem.
    $id = indsætLookup('cpr', '311278-1234');

    koerPurge();

    $r = DB::table('metis_lookups')->where('id', $id)->first();

    expect($r)->not->toBeNull()
        ->and($r->search_type)->toBe('cpr');
});

it('🚨 fjerner et CPR der er sluppet ind under en ANDEN type', function () {
    // Maalt 5/8: /lookup/person/, /lookup/address/ og /lookup/name/ gemte alle
    // et CPR foer guarden daekkede alle doere.
    $ids = collect(['person', 'address', 'name'])
        ->map(fn ($t) => indsætLookup($t, '311278-1234'));

    koerPurge();

    foreach ($ids as $id) {
        expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))->toBe('[fjernet: personnummer]');
    }
});

it('🚨 RØRER IKKE et CVR-nummer paa ti cifre', function () {
    // 🔑 Den vigtigste test. 4006033395 ligner et CPR for en ciffer-regex, men
    // dag "40" findes ikke. Uden datovalidering ville legitim soegehistorik
    // blive slettet — og tabet ville vaere tavst.
    $id = indsætLookup('cvr', '4006033395');

    koerPurge();

    expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))
        ->toBe('4006033395');
});

it('roerer ikke et almindeligt 8-cifret CVR', function () {
    $id = indsætLookup('cvr', '37792594');

    koerPurge();

    expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))
        ->toBe('37792594');
});

it('roerer ikke en adressesoegning', function () {
    $id = indsætLookup('address', 'Solhegnet 26, 4573');

    koerPurge();

    expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))
        ->toBe('Solhegnet 26, 4573');
});

it('haandterer CPR uden bindestreg', function () {
    $id = indsætLookup('cpr', '3112781234');

    koerPurge();

    expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))->toBe('[fjernet: personnummer]');
});

/**
 * 🚨 REVIEW-FUND 9/8: mellemrums-formen slap forbi.
 *
 * `SearchDetector::isCpr()` strimler whitespace FOER match; migrationens
 * foerste udkast gjorde ikke. Mellemrums-CPR er praecis den form kodebasen
 * selv har dokumenteret som den der slap igennem (copy-paste fra Word/PDF,
 * iOS-autokorrektur) — se `Search.php:105-107`.
 */
it('🚨 fjerner et CPR med MELLEMRUM', function () {
    $id = indsætLookup('cpr', '311278 1234');

    koerPurge();

    expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))
        ->toBe('[fjernet: personnummer]');
});

it('🚨 fjerner et CPR med foranstillet mellemrum', function () {
    $id = indsætLookup('person', ' 311278-1234');

    koerPurge();

    expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))
        ->toBe('[fjernet: personnummer]');
});

it('🔑 lagene er ENIGE: alt isCpr() kalder CPR med gyldig dato maskeres', function () {
    // Uden fælles normalisering kunne detektoren sige CPR mens migrationen
    // sagde nej — to lag uenige om samme værdi.
    $detector = new \TheFountainhead\Metis\Services\SearchDetector;

    foreach (['311278-1234', '3112781234', '311278 1234', ' 311278-1234'] as $form) {
        expect($detector->isCpr($form))->toBeTrue("detektor afviste {$form}");

        $id = indsætLookup('cpr', $form);
        koerPurge();

        expect(DB::table('metis_lookups')->where('id', $id)->value('search_term'))
            ->toBe('[fjernet: personnummer]', "migration beholdt {$form}");
    }
});
