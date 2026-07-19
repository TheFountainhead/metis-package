<?php

use TheFountainhead\Metis\Services\BbrUsageCategory;

it('maps residential codes to Bolig', function () {
    expect(BbrUsageCategory::label('120'))->toBe('Bolig'); // Ejerlejlighed
    expect(BbrUsageCategory::label('131'))->toBe('Bolig'); // Række-/kædehus
    expect(BbrUsageCategory::label('140'))->toBe('Bolig'); // Etagebolig
});

it('maps BBR 2.0 commercial codes to distinct categories', function () {
    expect(BbrUsageCategory::label('321'))->toBe('Kontor');
    expect(BbrUsageCategory::label('329'))->toBe('Kontor');
    expect(BbrUsageCategory::label('322'))->toBe('Butik');  // Detailhandel
    expect(BbrUsageCategory::label('324'))->toBe('Butik');  // Butikscenter
    expect(BbrUsageCategory::label('325'))->toBe('Butik');  // Tankstation
    expect(BbrUsageCategory::label('323'))->toBe('Lager');
    expect(BbrUsageCategory::label('331'))->toBe('Hotel/service'); // Hotel
    expect(BbrUsageCategory::label('333'))->toBe('Hotel/service'); // Restaurant
    expect(BbrUsageCategory::label('334'))->toBe('Hotel/service'); // Frisør/vaskeri
});

it('maps production, agriculture, transport, institution and holiday ranges', function () {
    expect(BbrUsageCategory::label('211'))->toBe('Landbrug');   // Stald til svin
    expect(BbrUsageCategory::label('222'))->toBe('Produktion'); // Industri
    expect(BbrUsageCategory::label('313'))->toBe('Transport');  // Parkering
    expect(BbrUsageCategory::label('412'))->toBe('Institution'); // Museum
    expect(BbrUsageCategory::label('510'))->toBe('Fritid');     // Sommerhus
    expect(BbrUsageCategory::label('910'))->toBe('Andet');      // Garage
});

it('classifies energy/utility 230-239 as Produktion (not the Andet junk bucket)', function () {
    expect(BbrUsageCategory::label('230'))->toBe('Produktion'); // El-/varmeværk
    expect(BbrUsageCategory::label('233'))->toBe('Produktion'); // Vandforsyning
    expect(BbrUsageCategory::label('234'))->toBe('Produktion'); // Affald/spildevand
    expect(BbrUsageCategory::label('239'))->toBe('Produktion'); // Anden forsyning
});

it('classifies the legacy aggregate office code 320 as Kontor', function () {
    expect(BbrUsageCategory::label('320'))->toBe('Kontor'); // udfaset kontor/handel/lager
});

it('covers range boundaries and the generic + default fallbacks', function () {
    // Produktion-grænser
    expect(BbrUsageCategory::label('220'))->toBe('Produktion');
    // Hotel/service-grænser (339 = anden serviceerhverv, hører til 330-familien)
    expect(BbrUsageCategory::label('330'))->toBe('Hotel/service');
    expect(BbrUsageCategory::label('339'))->toBe('Hotel/service');
    // Generisk 300-serie erhverv (fx 390 udfaset)
    expect(BbrUsageCategory::label('390'))->toBe('Erhverv');
    // Default junk-bucket for udefinerede serier (600-899)
    expect(BbrUsageCategory::label('620'))->toBe('Andet');
});

it('accepts integer codes, not just strings', function () {
    expect(BbrUsageCategory::label(321))->toBe('Kontor');
    expect(BbrUsageCategory::label(140))->toBe('Bolig');
});

it('does not fejl-bucket non-integer junk; returns it verbatim', function () {
    // ctype_digit afviser float-strings og alfanumerisk støj → ingen truncate til forkert bucket.
    expect(BbrUsageCategory::label('140abc'))->toBe('140abc');
    expect(BbrUsageCategory::label('321.0'))->toBe('321.0');
});

it('passes already-humanized labels through unchanged (idempotent)', function () {
    expect(BbrUsageCategory::label('Bolig'))->toBe('Bolig');
    expect(BbrUsageCategory::label('Erhverv'))->toBe('Erhverv');
});

it('returns null for empty input', function () {
    expect(BbrUsageCategory::label(null))->toBeNull();
    expect(BbrUsageCategory::label(''))->toBeNull();
});
