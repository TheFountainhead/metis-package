<?php

use TheFountainhead\Metis\Services\DisposableEmail;

it('blocks known disposable domains', function () {
    $checker = new DisposableEmail;
    expect($checker->isDisposable('test@mailinator.com'))->toBeTrue();
    expect($checker->isDisposable('test@guerrillamail.com'))->toBeTrue();
    expect($checker->isDisposable('test@tempmail.com'))->toBeTrue();
});

it('allows legitimate domains', function () {
    $checker = new DisposableEmail;
    expect($checker->isDisposable('user@gmail.com'))->toBeFalse();
    expect($checker->isDisposable('user@frankston.io'))->toBeFalse();
    expect($checker->isDisposable('user@company.dk'))->toBeFalse();
});
