<?php

use TheFountainhead\Metis\Services\LegalName;

/**
 * Selskabsnavne fra CVR og Tinglysningen står i VERSALER.
 *
 * Reglen er samlet ét sted, fordi den samme långiver ellers står forskelligt
 * to steder på SAMME side. Her testes den direkte — konsumenterne
 * (panthaver-tabellen og ejerstruktur-grafen) tester deres egen brug.
 */
it('gør et VERSAL-navn læsbart', function () {
    expect(LegalName::format('RINGKJØBING LANDBOBANK. AKTIESELSKAB'))
        ->toBe('Ringkjøbing Landbobank. Aktieselskab');
});

it('bevarer selskabsformen som juridisk betegnelse', function (string $input, string $expected) {
    // 🪤 mb_convert_case() laver ApS → Aps og A/S → A/s. Selskabsformen er en
    // juridisk betegnelse, ikke et almindeligt ord.
    expect(LegalName::format($input))->toBe($expected);
})->with([
    ['OMEGA FINANS APS', 'Omega Finans ApS'],
    ['DRAUPNIR INVESTMENT ADVISORS A/S', 'Draupnir Investment Advisors A/S'],
    ['SOME PARTNERSHIP I/S', 'Some Partnership I/S'],
    ['ET SELSKAB P/S', 'Et Selskab P/S'],
    ['ET KOMMANDIT K/S', 'Et Kommandit K/S'],
    ['ET ANDELSSELSKAB AMBA', 'Et Andelsselskab AmbA'],
    ['EN FORENING FMBA', 'En Forening FmbA'],
    ['ET SELSKAB SMBA', 'Et Selskab SmbA'],
]);

it('rører ikke et navn der allerede er skrevet rigtigt', function () {
    expect(LegalName::format('Omega Finans ApS'))->toBe('Omega Finans ApS');
});

it('retter kun selskabsformen til sidst, ikke midt i et navn', function () {
    // 🚨 Uden ' '-præfikset i str_ends_with ville "Apside" få rettet sin
    // afslutning. Formen er et selvstændigt ord, ikke en endelse.
    expect(LegalName::format('LAPS HOLDING'))->toBe('Laps Holding');
});

it('lader udenlandske navne med accenter være læsbare', function () {
    // Målt i prod: 290 mio. hos denne kreditor. Intet CVR, tysk stavemåde.
    expect(LegalName::format('LANDESBANK HESSEN-THÜRINGEN GIROZENTRALE'))
        ->toBe('Landesbank Hessen-Thüringen Girozentrale');
});
