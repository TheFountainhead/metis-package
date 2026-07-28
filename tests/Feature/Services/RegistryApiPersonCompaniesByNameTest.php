<?php

use Illuminate\Support\Facades\Http;
use TheFountainhead\Metis\Services\RegistryApi;

it('returns the companies payload for a matched name', function () {
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => [['cvr' => '12345678']]],
    ])]);

    $result = app(RegistryApi::class)->fetchCompaniesByName('Test Person');

    expect($result['person_name'])->toBe('Test Person')
        ->and($result['companies'])->toHaveCount(1);
});

it('returns null on a 404 so the caller can show a genuine empty state', function () {
    // registry-api svarer 404 når deltageren slet ikke findes (Task 1-2's
    // kontrakt) — IKKE et tomt companies-array. post()'s errorFrom() giver
    // ['error' => 'upstream_error', 'status' => 404], så metoden skal mappe
    // det aktivt til null her, ikke antage at post() selv giver null.
    Http::fake(['*person-companies-by-name*' => Http::response(['error' => 'Person not found'], 404)]);

    expect(app(RegistryApi::class)->fetchCompaniesByName('Ukendt'))->toBeNull();
});

it('surfaces a transport failure as an error shape, not as null', function () {
    // null ≠ tom: en fejl må ALDRIG kunne læses som "ingen selskaber". En
    // transportfejl (status 0) skal forblive en synlig fejl-shape, adskilt
    // fra 404 (status 404 → null ovenfor).
    Http::fake(['*person-companies-by-name*' => Http::failedConnection('cURL error 28')]);

    $result = app(RegistryApi::class)->fetchCompaniesByName('Test Person');

    expect($result)->not->toBeNull()
        ->and($result['error'])->toBe('upstream_error')
        ->and($result['status'])->toBe(0);
});

it('sends the name in the request body', function () {
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => ['person_name' => 'Test Person', 'companies' => []],
    ])]);

    app(RegistryApi::class)->fetchCompaniesByName('Test Person');

    Http::assertSent(function ($request) {
        return $request->url() === 'https://registry-api.test/v1/cvr/person-companies-by-name'
            && $request['name'] === 'Test Person';
    });
});
