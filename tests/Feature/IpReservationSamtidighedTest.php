<?php

use Illuminate\Cache\FileStore;
use Illuminate\Filesystem\Filesystem;
use Symfony\Component\Process\Process;

/**
 * Fix-runde 1 paa #193, review F1: IP-reservationen skal vaere atomisk paa
 * prods `file`-cache.
 *
 * `FileStore::increment()` er laes-laeg-til-skriv uden laas. Revieweren
 * maalte 57 af 60 parallelle reservationer godkendt med graensen 5. Her
 * koeres den RIGTIGE `LookupAccess::reserverIpPlads()` i 40 separate
 * PHP-processer mod samme fil-cache, sluppet loes samtidigt via en
 * barriere, hver med 25 forsoeg i traek (1000 i alt). Praecis 5 maa
 * godkendes, og taelleren skal staa paa 5.
 */
/**
 * Starter $antal processer, der hver proever $forsoeg reservationer, slipper
 * dem loes samtidigt og returnerer [godkendt i alt, taellerens slutvaerdi].
 */
function ipKapLoeb(int $antal, int $forsoeg, int $graense): array
{
    $dir = sys_get_temp_dir().'/metis-ip-race-'.bin2hex(random_bytes(6));
    mkdir($dir.'/cache', 0777, true);
    $go = $dir.'/go';
    $noegle = 'metis:anon_lookups:ip:'.sha1('203.0.113.5');
    $worker = __DIR__.'/Fixtures/ip-reservation-worker.php';

    try {
        $processer = [];
        for ($i = 0; $i < $antal; $i++) {
            $p = new Process([PHP_BINARY, $worker, $dir.'/cache', $go, $noegle, (string) $forsoeg, (string) $graense]);
            $p->setTimeout(60);
            $p->start();
            $processer[] = $p;
        }

        // Giv alle processer tid til at starte og vente ved barrieren.
        usleep(1_500_000);
        touch($go);

        $godkendt = 0;
        foreach ($processer as $p) {
            $p->wait();
            expect($p->getExitCode())->toBe(0, $p->getErrorOutput())
                ->and(trim($p->getOutput()))->toMatch('/^\d+$/');
            $godkendt += (int) trim($p->getOutput());
        }

        $taeller = (int) (new FileStore(new Filesystem, $dir.'/cache'))->get($noegle);
        fwrite(STDERR, "\n[F1-race] {$antal} processer x {$forsoeg} forsoeg, graense {$graense}: {$godkendt} godkendt, taeller {$taeller}\n");

        return [$godkendt, $taeller];
    } finally {
        (new Filesystem)->deleteDirectory($dir);
    }
}

it('🚨 F1: 40 samtidige processer mod en FILE-store faar praecis 5 godkendt (graense 5)', function () {
    [$godkendt, $taeller] = ipKapLoeb(antal: 40, forsoeg: 25, graense: 5);

    expect($godkendt)->toBe(5)->and($taeller)->toBe(5);
});

it('🚨 F1: under vedvarende kapløb godkendes aldrig flere end graensen (graense 300, 1000 forsoeg)', function () {
    // Med graense 5 er kapløbsvinduet kun de foerste millisekunder, saa en
    // manglende laas viser sig ikke hver gang. Med graense 300 kaemper alle
    // processer om taelleren hele vejen op, og et tabt increment giver
    // overgodkendelse. Det er denne test, mutationsbeviset hviler paa.
    [$godkendt, $taeller] = ipKapLoeb(antal: 40, forsoeg: 25, graense: 300);

    expect($godkendt)->toBe(300)->and($taeller)->toBe(300);
});
