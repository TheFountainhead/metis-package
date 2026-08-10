<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

/**
 * Opbevaringsgraense paa `metis_lookups`.
 *
 * 🚨 MAALT PAA PROD 9/8: 8.265 raekker fra 25. marts, ALLE med `ip_address`,
 * ingen oprydning nogensinde. En komplet log over hvem der soegte paa hvad.
 *
 * 🔑 To trin, fordi felterne har forskellig levetid: IP'en peger paa en person
 * og mister sin nytte paa dage; soegetype og tidspunkt er statistik i hele
 * perioden. Anonymisér efter 30, slet efter 90.
 */
function lookupRaekke(string $ip, int $dageGammel, array $extra = []): int
{
    return DB::table('metis_lookups')->insertGetId(array_merge([
        'session_id' => 'sess-'.$dageGammel.'-'.$ip,
        'search_type' => 'cvr',
        'search_term' => '37792594',
        'ip_address' => $ip,
        'is_cross_reference' => false,
        'created_at' => now()->subDays($dageGammel),
        'updated_at' => now()->subDays($dageGammel),
    ], $extra));
}

it('anonymiserer IP paa raekker aeldre end 30 dage', function () {
    $id = lookupRaekke('37.96.17.93', 45);

    $this->artisan('metis:prune-lookups')->assertSuccessful();

    expect(DB::table('metis_lookups')->where('id', $id)->value('ip_address'))
        ->toBe('37.96.17.0');
});

it('🪤 lader friske raekker beholde deres fulde IP', function () {
    // Under 30 dage: IP'en har stadig operationel vaerdi til misbrugsanalyse.
    $id = lookupRaekke('37.96.17.93', 5);

    $this->artisan('metis:prune-lookups')->assertSuccessful();

    expect(DB::table('metis_lookups')->where('id', $id)->value('ip_address'))
        ->toBe('37.96.17.93');
});

it('🚨 sletter raekker aeldre end 90 dage helt', function () {
    $id = lookupRaekke('37.96.17.93', 120);

    $this->artisan('metis:prune-lookups')->assertSuccessful();

    expect(DB::table('metis_lookups')->where('id', $id)->exists())->toBeFalse();
});

it('beholder raekken mellem 30 og 90 dage — anonymiseret, ikke slettet', function () {
    // 🔑 Kernen i to-trins-modellen: statistikken overlever, personen goer ikke.
    $id = lookupRaekke('37.96.17.93', 60);

    $this->artisan('metis:prune-lookups')->assertSuccessful();

    $r = DB::table('metis_lookups')->where('id', $id)->first();

    expect($r)->not->toBeNull()
        ->and($r->ip_address)->toBe('37.96.17.0')
        ->and($r->search_type)->toBe('cvr');
});

it('🪤 afviser en slet-graense der ligger FOER anonymiserings-graensen', function () {
    // Ellers ville raekkerne blive slettet foer de nogensinde blev
    // anonymiseret, og anonymiseringen var doed kode der saa ud som beskyttelse.
    $this->artisan('metis:prune-lookups --anonymise-after=90 --delete-after=30')
        ->assertFailed();
});

it('toerloeb skriver intet', function () {
    $gammel = lookupRaekke('37.96.17.93', 120);
    $mellem = lookupRaekke('37.96.17.94', 60);

    $this->artisan('metis:prune-lookups --dry-run')->assertSuccessful();

    expect(DB::table('metis_lookups')->where('id', $gammel)->exists())->toBeTrue()
        ->and(DB::table('metis_lookups')->where('id', $mellem)->value('ip_address'))
        ->toBe('37.96.17.94');
});

it('🪤 er idempotent — en allerede anonymiseret IP roeres ikke igen', function () {
    // Uden `NOT LIKE '%.0'` ville 37.96.17.0 blive "anonymiseret" hver nat og
    // taelle med i rapporten, saa tallet aldrig faldt til nul.
    $id = lookupRaekke('37.96.17.0', 45);

    $this->artisan('metis:prune-lookups')->assertSuccessful();
    $this->artisan('metis:prune-lookups')
        ->expectsOutputToContain('anonymiseret: 0')
        ->assertSuccessful();

    expect(DB::table('metis_lookups')->where('id', $id)->value('ip_address'))
        ->toBe('37.96.17.0');
});
