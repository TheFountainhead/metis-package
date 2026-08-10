<?php

/**
 * `x-data`-attributten må ikke indeholde dobbelte anførselstegn.
 *
 * 🚨 MAALT I BROWSEREN PAA PROD 10/8: adresse-sider viste RAA JAVASCRIPT som
 * synlig tekst (104 px hoej blok) og kortet renderede ALDRIG.
 *
 * Aarsagen var én kommentar inde i `x-data="{ ... }"`:
 *
 *     // Leaflet kaster "Map container is already initialized"
 *
 * De to anfoerselstegn lukkede HTML-attributten for tidligt. Alpine fik et
 * afkortet udtryk, kastede `Unexpected token ')'`, og komponenten blev aldrig
 * initialiseret. Resten af JS'en blev til synlig tekst.
 *
 * 🪤 FEJLEN ER USYNLIG FOR ALT ANDET END EN BROWSER. Siden svarede HTTP 200,
 * Blade renderede uden fejl, og PHP-testene var groenne. Kun `pageerror` i en
 * rigtig browser afsloerede den — derfor denne test, der laeser filen som
 * tekst frem for at stole paa at "det ser rigtigt ud".
 */
it('🚨 map-panelets x-data indeholder ingen dobbelte anfoerselstegn', function () {
    $fil = __DIR__.'/../../resources/views/livewire/map-panel.blade.php';

    $indhold = file_get_contents($fil);

    // Udtraek alt mellem `x-data="` og det afsluttende `"` paa egen linje.
    expect($indhold)->toContain('x-data="{');

    $start = strpos($indhold, 'x-data="{');
    $slut = strpos($indhold, "\n        }\"", $start);

    expect($slut)->not->toBeFalse();

    $xdata = substr($indhold, $start + strlen('x-data="'), $slut - $start - strlen('x-data="'));

    // 🪤 TAEL, brug ikke `toContain('"', 'besked')`. Pests `toContain()` tager
    // FLERE vaerdier at soege efter, ikke en fejlbesked — andet argument blev
    // altsaa laest som "find ogsaa denne streng", og assertionen bestod med to
    // anfoerselstegn indsat. Fanget af detektions-tjekket 10/8.
    $antal = substr_count($xdata, '"');

    expect($antal)->toBe(0);
});
