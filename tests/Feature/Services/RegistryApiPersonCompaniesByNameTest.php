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

it('returns an empty companies array on a 404 so the caller renders a genuine empty state', function () {
    // registry-api svarer 404 når deltageren slet ikke findes (Task 1-2's
    // kontrakt) — det er en SETTLED tom-tilstand, ikke en fejl. post()'s
    // errorFrom() giver ['error' => 'upstream_error', 'status' => 404], så
    // metoden skal mappe det aktivt til ['companies' => []] her — IKKE null,
    // som PersonStructure::attempt() normaliserer til 'failed'. Reviewed
    // fund: en advokat der søger på et navn der ikke findes i CVR så før
    // denne rettelse "Selskabsrelationerne kunne ikke hentes." med en
    // "Prøv igen"-knap der aldrig kunne lykkes.
    Http::fake(['*person-companies-by-name*' => Http::response(['error' => 'Person not found'], 404)]);

    expect(app(RegistryApi::class)->fetchCompaniesByName('Ukendt'))->toBe(['companies' => []]);
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

it('does not swallow a success payload whose top-level data happens to carry status 404', function () {
    // 'status' er allerede et forretningsfelt andre steder i RegistryApi (fx
    // company['status'] === 'NORMAL' i searchPersonByName():116,:130 og
    // fetchCompany():89) — samme nøgle som fejl-konvolutten bruger. Guarden i
    // fetchCompaniesByName() må derfor kun trigge på et ÆGTE fejl-konvolut
    // (isset($result['error'])) — aldrig blot fordi et 200-succes-payload
    // tilfældigvis bærer en 'status'-nøgle med værdien 404.
    Http::fake(['*person-companies-by-name*' => Http::response([
        'data' => [
            'person_name' => 'Test Person',
            'companies' => [['cvr' => '12345678']],
            'status' => 404,
        ],
    ])]);

    $result = app(RegistryApi::class)->fetchCompaniesByName('Test Person');

    expect($result)->not->toBeNull()
        ->and($result['companies'])->toHaveCount(1);
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
