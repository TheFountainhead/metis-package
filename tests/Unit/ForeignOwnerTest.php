<?php

use TheFountainhead\Metis\Services\ForeignOwner;

/**
 * ERST krav 1.5: "Udenlandsk kapital: land + enhedstype for enhver
 * grænseoverskridende investering". Landet skal stå på dansk, ikke som kode.
 */
it('names the countries we measured in Danish', function (string $code, string $name) {
    expect(ForeignOwner::countryName($code))->toBe($name);
})->with([
    ['GB', 'Storbritannien'],
    ['US', 'USA'],
    ['NL', 'Nederlandene'],
    ['DE', 'Tyskland'],
    ['LU', 'Luxembourg'],
    ['CH', 'Schweiz'],
    ['SE', 'Sverige'],
    ['KY', 'Caymanøerne'],
    ['se', 'Sverige'],
]);

it('falls back to intl for a country outside the fixed table', function () {
    expect(ForeignOwner::countryName('BR'))->toBe('Brasilien');
})->skip(! class_exists(Locale::class), 'intl ikke installeret');

it('shows the code rather than nothing for an unknown country', function () {
    expect(ForeignOwner::countryName('XX'))->toBe('XX')
        ->and(ForeignOwner::countryName(null))->toBeNull()
        ->and(ForeignOwner::countryName(''))->toBeNull();
});

it('translates CVR entity types into plain Danish', function () {
    expect(ForeignOwner::unitTypeLabel('ANDEN_DELTAGER'))->toBe('selskab/fond')
        ->and(ForeignOwner::unitTypeLabel('PERSON'))->toBe('person')
        ->and(ForeignOwner::unitTypeLabel('VIRKSOMHED'))->toBe('virksomhed')
        ->and(ForeignOwner::unitTypeLabel('NY_TYPE'))->toBe('ny type')
        ->and(ForeignOwner::unitTypeLabel(null))->toBeNull();
});

it('joins country and entity type, and drops what is unknown', function () {
    expect(ForeignOwner::label('SE', 'ANDEN_DELTAGER'))->toBe('Sverige · selskab/fond')
        ->and(ForeignOwner::label(null, 'ANDEN_DELTAGER'))->toBe('selskab/fond')
        ->and(ForeignOwner::label('GB', null))->toBe('Storbritannien')
        ->and(ForeignOwner::label(null, null))->toBeNull();
});
