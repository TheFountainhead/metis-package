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
