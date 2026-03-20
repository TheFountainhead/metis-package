<?php

use TheFountainhead\Metis\Services\FreeEmailDetector;

it('detects gmail as free email', function () {
    $detector = new FreeEmailDetector();
    expect($detector->isFreeEmail('anne@gmail.com'))->toBeTrue();
});

it('detects hotmail as free email', function () {
    $detector = new FreeEmailDetector();
    expect($detector->isFreeEmail('user@hotmail.com'))->toBeTrue();
});

it('allows business email', function () {
    $detector = new FreeEmailDetector();
    expect($detector->isFreeEmail('anne@carlsberg.dk'))->toBeFalse();
});
