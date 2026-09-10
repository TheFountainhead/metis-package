<?php

/**
 * Kapitalhistorik-tabellen: kolonnebredder.
 *
 * 🚨 Uden eksplicitte bredder fordeler browseren efter INDHOLD. De fem foerste
 * kolonner har `whitespace-nowrap` og kan ikke brydes, saa de tager den plads de
 * kraever — og ejer-kolonnen, den eneste med brydbar tekst, absorberer hele
 * underskuddet. Maalt i Word-eksporten af ERST-tilbuddet 10/9-2026:
 * investornavne brudt over 4-6 linjer ved siden af halvtomme talkolonner.
 */
$blade = fn () => file_get_contents(
    __DIR__.'/../../resources/views/livewire/sections/company-funding.blade.php'
);

it('🚨 giver hver kolonne i kapitaltabellen en eksplicit bredde', function () use ($blade) {
    preg_match('/<thead>(.*?)<\/thead>/s', $blade(), $m);
    $thead = $m[1] ?? '';

    $kolonner = substr_count($thead, '<th ');
    $medBredde = preg_match_all('/<th [^>]*\bw-\[\d+%\]/', $thead);

    expect($kolonner)->toBeGreaterThan(0)
        ->and($medBredde)->toBe($kolonner,
            "Alle $kolonner kolonner skal have en w-[n%]-bredde; kun $medBredde har det.");
});

it('🪤 lader bredderne summe til 100 %', function () use ($blade) {
    preg_match('/<thead>(.*?)<\/thead>/s', $blade(), $m);
    preg_match_all('/w-\[(\d+)%\]/', $m[1] ?? '', $b);

    expect(array_sum(array_map('intval', $b[1])))->toBe(100);
});

it('🪤 giver ejer-kolonnen mest plads — den er den eneste med brydbar tekst', function () use ($blade) {
    preg_match('/<thead>(.*?)<\/thead>/s', $blade(), $m);
    preg_match_all('/w-\[(\d+)%\]/', $m[1] ?? '', $b);
    $bredder = array_map('intval', $b[1]);

    expect(end($bredder))->toBe(max($bredder),
        'Ejer-kolonnen (sidste) skal vaere den bredeste — ellers brydes investornavnene.');
});

it('🚨 river ALDRIG procenten fra sit ejernavn', function () use ($blade) {
    // En linje der begynder med "→ 15%" hoerer visuelt til ejeren OVENOVER.
    expect($blade())->toContain('whitespace-nowrap"> → ');
});
