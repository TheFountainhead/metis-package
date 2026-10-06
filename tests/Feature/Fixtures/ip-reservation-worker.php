<?php

/**
 * Én proces i samtidighedstesten for IP-reservationen (review F1 paa #193).
 *
 * Koerer den RIGTIGE `LookupAccess::reserverIpPlads()` mod en `file`-store,
 * som prod (`CACHE_STORE=file`). Ingen Laravel-app: kun de bindinger koden
 * bruger (config, cache, date, log), saa processen starter hurtigt nok til at
 * de mange workers faktisk overlapper.
 *
 * Brug: php ip-reservation-worker.php <cache-dir> <go-fil> <noegle> <forsoeg> <graense>
 * Proever <forsoeg> reservationer i traek og skriver antallet godkendte paa
 * stdout. Flere forsoeg pr. proces giver mange flere overlappende vinduer,
 * saa et kapløb uden laas viser sig hver gang, ikke kun af og til.
 */

require __DIR__.'/../../../vendor/autoload.php';

use Illuminate\Cache\CacheManager;
use Illuminate\Config\Repository;
use Illuminate\Container\Container;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Facade;
use Psr\Log\NullLogger;
use TheFountainhead\Metis\Services\LookupAccess;

[, $cacheDir, $goFil, $noegle, $forsoeg, $graense] = $argv;

$app = new Container;
Container::setInstance($app);
Facade::setFacadeApplication($app);

$app->instance('config', new Repository([
    'cache' => [
        'default' => 'file',
        'stores' => ['file' => ['driver' => 'file', 'path' => $cacheDir, 'lock_path' => $cacheDir]],
        'prefix' => '',
    ],
    'metis' => ['gating' => ['ip_daily_limit' => (int) $graense, 'ip_window_hours' => 24]],
]));
$app->instance('files', new Filesystem);
$app->singleton('cache', fn ($app) => new CacheManager($app));
$app->singleton('cache.store', fn ($app) => $app['cache']->driver());
$app->instance('date', new DateFactory);
$app->instance('log', new NullLogger);

// Barriere: alle processer venter paa samme signal, saa de rammer taelleren samtidigt.
$frist = microtime(true) + 20;
while (! file_exists($goFil) && microtime(true) < $frist) {
    usleep(500);
}

$adgang = new LookupAccess;
$godkendt = 0;
for ($i = 0; $i < (int) $forsoeg; $i++) {
    $godkendt += $adgang->reserverIpPlads($noegle) ? 1 : 0;
}

echo $godkendt;
