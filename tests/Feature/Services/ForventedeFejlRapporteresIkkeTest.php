<?php

use Illuminate\Support\Facades\Http;
use TheFountainhead\Metis\Services\RegistryApi;

/*
 * 🚨 EN FORVENTET 4xx ER IKKE EN APPLIKATIONSFEJL.
 *
 * `errorFrom()` kaldte `report($e)` for ALT — ogsaa et 422 der blot betyder
 * "adressen kan ikke opløses". Flare fik da en fuld stak, umulig at skelne
 * fra et crash, og #9104992 samlede 117 forekomster hvoraf de fleste var en
 * HAANDTERET tilstand vi selv viser brugeren en besked for.
 *
 * 🔑 SMALT, ikke bredt. Kun 422 med registry-apis matrikel-besked tie-stilles.
 * Alle andre 4xx og ALLE 5xx rapporteres uaendret — mister vi dem, mister vi
 * signalet om at upstream begynder at afvise noget vi troede var gyldigt.
 *
 * Maalt mod PROD 20/8 (den ægte krop):
 *   {"message":"The matrikel id field is required when street / number / zip
 *    is not present. (and 2 more errors)","errors":{...}}
 *
 * 🪤 Returværdien er UAENDRET. Kun rapporteringen falder væk — alle 14+
 * forbrugere ser stadig `['error' => …, 'status' => 422]`, saa brugeren faar
 * praecis samme besked som foer.
 */

/**
 * Taeller report()-kald ved at erstatte exception-handleren.
 *
 * 🪤 Foerste udkast asserterede kun paa RETURVAERDIEN — men den er UAENDRET
 * af denne rettelse, saa testen ville vaere groen uanset om `report()` blev
 * kaldt. Praecis den proxy-faelde der har kostet otte review-runder i dag:
 * mål TINGEN (blev der rapporteret?), ikke en stedfortræder for den.
 */
function rapporteredeFejl(callable $handling): int
{
    $antal = 0;

    app()->bind(Illuminate\Contracts\Debug\ExceptionHandler::class, function () use (&$antal) {
        return new class($antal) implements Illuminate\Contracts\Debug\ExceptionHandler
        {
            public function __construct(public int &$antal) {}

            public function report(Throwable $e): void
            {
                $this->antal++;
            }

            public function shouldReport(Throwable $e): bool
            {
                return true;
            }

            public function render($request, Throwable $e)
            {
                throw $e;
            }

            public function renderForConsole($output, Throwable $e): void {}
        };
    });

    $handling();

    return $antal;
}

it('rapporterer IKKE et forventet matrikel-422', function () {
    Http::fake(['*' => Http::response([
        'message' => 'The matrikel id field is required when street / number / zip is not present. (and 2 more errors)',
    ], 422)]);

    $svar = null;
    $antal = rapporteredeFejl(function () use (&$svar) {
        $svar = app(RegistryApi::class)->fetchProperty(['zip' => '4653', 'street' => '', 'number' => '']);
    });

    // SELVE TINGEN: ingen rapportering.
    expect($antal)->toBe(0);
    // Returværdien er UAENDRET — brugeren faar samme besked som foer.
    expect($svar)->toBe(['error' => 'upstream_error', 'status' => 422]);
});

it('rapporterer STADIG et 500 fra upstream', function () {
    Http::fake(['*' => Http::response(['message' => 'Server Error'], 500)]);

    $svar = null;
    $antal = rapporteredeFejl(function () use (&$svar) {
        $svar = app(RegistryApi::class)->fetchProperty(['zip' => '4653', 'street' => 'A', 'number' => '1']);
    });

    expect($antal)->toBe(1);
    expect($svar)->toBe(['error' => 'upstream_error', 'status' => 500]);
});

it('rapporterer STADIG et 422 der IKKE handler om matriklen', function () {
    Http::fake(['*' => Http::response([
        'message' => 'The api key field is invalid.',
    ], 422)]);

    $svar = null;
    $antal = rapporteredeFejl(function () use (&$svar) {
        $svar = app(RegistryApi::class)->fetchProperty(['zip' => '4653', 'street' => 'A', 'number' => '1']);
    });

    expect($antal)->toBe(1);
    expect($svar)->toBe(['error' => 'upstream_error', 'status' => 422]);
});

it('rapporterer STADIG et 404', function () {
    Http::fake(['*' => Http::response(['message' => 'Not Found'], 404)]);

    $svar = null;
    $antal = rapporteredeFejl(function () use (&$svar) {
        $svar = app(RegistryApi::class)->fetchProperty(['zip' => '4653', 'street' => 'A', 'number' => '1']);
    });

    expect($antal)->toBe(1);
    expect($svar)->toBe(['error' => 'upstream_error', 'status' => 404]);
});

/*
 * 🚨 REVIEW-FUND: en ARRAY-vaerdi i `message` kastede INDE I catch-blokken.
 *
 * `errorFrom()` koerer inde i `catch (RequestException $e)`. Et throw dér
 * fanges af INTET — jeg gjorde en haandteret fejl til en uhaandteret, i
 * selve koden der findes for at haandtere fejl.
 *
 * Maalt: `['message' => ['matrikel id field is required']]` gav
 * `ErrorException: Array to string conversion`, og exceptionen SLAP UD af
 * fetchProperty() i stedet for at blive til ['error' => …].
 *
 * 🪤 Ville ramme HVER 422, ikke kun matrikel-varianten — `json('message')`
 * kaldes foer beskeden tjekkes. Laravel-validationssvar baerer legitimt
 * arrays.
 */
it('overlever et 422 hvor message er et ARRAY', function () {
    Http::fake(['*' => Http::response([
        'message' => ['matrikel id field is required'],
        'errors' => ['street' => ['required']],
    ], 422)]);

    $svar = null;
    $antal = rapporteredeFejl(function () use (&$svar) {
        $svar = app(RegistryApi::class)->fetchProperty(['zip' => '4653', 'street' => '', 'number' => '']);
    });

    // Maa ikke kaste — og en ikke-streng besked kan ikke matches, saa den
    // skal rapporteres (fail-safe: hellere stoej end tavshed).
    expect($svar)->toBe(['error' => 'upstream_error', 'status' => 422])
        ->and($antal)->toBe(1);
});

/*
 * 🚨 REVIEW-FUND: `str_contains` er UANKRET.
 *
 * Maalt: 'Your api token is invalid: matrikel id field is required'
 * => rapporteret 0. En upstream-fejl der CITERER validerings-teksten
 * forsvandt fra Flare — praecis det "mister signalet om at upstream afviser
 * noget vi troede var gyldigt" som kommentaren siger den beskytter imod.
 */
it('rapporterer STADIG et 422 der blot CITERER matrikel-teksten', function () {
    Http::fake(['*' => Http::response([
        'message' => 'Your api token is invalid: matrikel id field is required',
    ], 422)]);

    $antal = rapporteredeFejl(function () {
        app(RegistryApi::class)->fetchProperty(['zip' => '4653', 'street' => 'A', 'number' => '1']);
    });

    expect($antal)->toBe(1);
});

/*
 * 🚨 "PERSON NOT FOUND" ER ET SVAR, IKKE EN FEJL (Flare 109120 #3).
 *
 * Et navn i søgefeltet går til `/v1/cvr/person-roles`. Findes der ingen
 * CVR-deltager med navnet, svarer registry-api 404 med sin egen krop
 * (CvrController::personRolesByName), og `searchPersonByName()` giver []
 * — brugeren ser selskaberne eller "ingen resultater". Alligevel sendte
 * `errorFrom()` hvert eneste af dem til Flare med fuld stak: 23 forekomster.
 *
 * Kroppen er den ægte fra Flare 26/10:
 *   {"error":{"code":"not_found","message":"Person not found","details":[],
 *    "request_id":"req_6adf3e1dad674"}}
 *
 * 🚨 registry-api svarer også denne 404, når dens eget CVR-opslag fejler.
 * Den fejl skal rapporteres DÉR (registry-api), ikke gættes ud af en 404 her.
 */
function personIkkeFundetKrop(): array
{
    return ['error' => [
        'code' => 'not_found',
        'message' => 'Person not found',
        'details' => [],
        'request_id' => 'req_6adf3e1dad674',
    ]];
}

it('rapporterer IKKE registry-apis "Person not found" fra et navneopslag', function () {
    Http::fake(['*/v1/cvr/person-roles' => Http::response(personIkkeFundetKrop(), 404)]);

    $svar = null;
    $antal = rapporteredeFejl(function () use (&$svar) {
        $svar = app(RegistryApi::class)->fetchPersonRoles('Fritz Schur');
    });

    expect($antal)->toBe(0);
    // Returværdien er UÆNDRET — searchPersonByName() giver stadig [].
    expect($svar)->toBe(['error' => 'upstream_error', 'status' => 404]);
});

it('viser stadig et tomt personresultat, ikke en undtagelse', function () {
    Http::fake(['*/v1/cvr/person-roles' => Http::response(personIkkeFundetKrop(), 404)]);

    $svar = null;
    $antal = rapporteredeFejl(function () use (&$svar) {
        $svar = app(RegistryApi::class)->searchPersonByName('Fritz Schur');
    });

    expect($svar)->toBe([])->and($antal)->toBe(0);
});

it('rapporterer STADIG et 404 med en anden not_found-besked', function () {
    // SMALT: kun personens "findes ikke". Et "Company not found" på et CVR,
    // vi selv har fundet, kan betyde at upstream har mistet noget.
    Http::fake(['*' => Http::response(['error' => [
        'code' => 'not_found',
        'message' => 'Company not found',
    ]], 404)]);

    $antal = rapporteredeFejl(function () {
        app(RegistryApi::class)->fetchPersonRoles('Fritz Schur');
    });

    expect($antal)->toBe(1);
});

it('rapporterer STADIG en "Person not found"-besked med en anden status', function () {
    Http::fake(['*' => Http::response(personIkkeFundetKrop(), 500)]);

    $antal = rapporteredeFejl(function () {
        app(RegistryApi::class)->fetchPersonRoles('Fritz Schur');
    });

    expect($antal)->toBe(1);
});

it('overlever en 404 hvor error er en STRENG, ikke et objekt', function () {
    Http::fake(['*' => Http::response(['error' => 'Person not found'], 404)]);

    $svar = null;
    $antal = rapporteredeFejl(function () use (&$svar) {
        $svar = app(RegistryApi::class)->fetchPersonRoles('Fritz Schur');
    });

    expect($svar)->toBe(['error' => 'upstream_error', 'status' => 404])
        ->and($antal)->toBe(1);
});
