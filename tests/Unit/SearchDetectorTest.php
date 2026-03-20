<?php

use TheFountainhead\Metis\Services\SearchDetector;

it('detects CPR format', function () {
    $detector = new SearchDetector;
    expect($detector->detect('120590-1234'))->toBe('cpr');
    expect($detector->detect('1205901234'))->toBe('cpr');
});

it('detects CVR format (8 digits)', function () {
    $detector = new SearchDetector;
    expect($detector->detect('12345678'))->toBe('cvr');
    expect($detector->detect('87654321'))->toBe('cvr');
});

it('detects company name with legal suffix', function () {
    $detector = new SearchDetector;
    expect($detector->detect('Frankston ApS'))->toBe('company_name');
    expect($detector->detect('Carlsberg A/S'))->toBe('company_name');
    expect($detector->detect('Hansen Holding IVS'))->toBe('company_name');
});

it('detects company name with Holding/Invest/Fond suffixes', function () {
    $detector = new SearchDetector;
    expect($detector->detect('Øhlers Holding'))->toBe('company_name');
    expect($detector->detect('Nordic Invest'))->toBe('company_name');
    expect($detector->detect('Dannebrog Fond'))->toBe('company_name');
});

it('detects company name with extended legal forms', function () {
    $detector = new SearchDetector;
    expect($detector->detect('Andelsbolig FMBA'))->toBe('company_name');
    expect($detector->detect('Kooperativ SMBA'))->toBe('company_name');
    expect($detector->detect('European SCE'))->toBe('company_name');
    expect($detector->detect('Sparekassen Fund'))->toBe('company_name');
});

it('detects address patterns', function () {
    $detector = new SearchDetector;
    expect($detector->detect('Bredgade 40'))->toBe('address');
    expect($detector->detect('Nørrebrogade 15, 2200'))->toBe('address');
    expect($detector->detect('Vestergade 1A'))->toBe('address');
});

it('defaults to name search for plain text', function () {
    $detector = new SearchDetector;
    expect($detector->detect('Anders Hansen'))->toBe('name');
    expect($detector->detect('Frederik'))->toBe('name');
});

it('trims whitespace', function () {
    $detector = new SearchDetector;
    expect($detector->detect('  12345678  '))->toBe('cvr');
});
