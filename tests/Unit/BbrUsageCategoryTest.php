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

it('passes already-humanized labels through unchanged (idempotent)', function () {
    expect(BbrUsageCategory::label('Bolig'))->toBe('Bolig');
    expect(BbrUsageCategory::label('Erhverv'))->toBe('Erhverv');
});

it('returns null for empty input', function () {
    expect(BbrUsageCategory::label(null))->toBeNull();
    expect(BbrUsageCategory::label(''))->toBeNull();
});
