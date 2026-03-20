<?php

use Illuminate\Support\Facades\Http;
use TheFountainhead\Metis\Services\CompanyEmailResolver;

it('resolves business domain to CVR', function () {
    Http::fake([
        '*/v1/cvr/search-by-name' => Http::response([
            'data' => ['companies' => [
                ['cvr' => '25020913', 'name' => 'Carlsberg A/S', 'industry' => 'Fremstilling af øl'],
            ]],
        ]),
    ]);

    $resolver = app(CompanyEmailResolver::class);
    $result = $resolver->resolve('anne@carlsberg.dk');

    expect($result)->toHaveCount(1);
    expect($result[0]['cvr'])->toBe('25020913');
});

it('returns empty for unknown domain', function () {
    Http::fake(['*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => []]])]);

    $resolver = app(CompanyEmailResolver::class);
    expect($resolver->resolve('anne@unknowndomain.dk'))->toBeEmpty();
});

it('returns multiple matches for ambiguous domain', function () {
    Http::fake([
        '*/v1/cvr/search-by-name' => Http::response(['data' => ['companies' => [
            ['cvr' => '10000001', 'name' => 'Holding A/S', 'industry' => 'Holding'],
            ['cvr' => '10000002', 'name' => 'Drift ApS', 'industry' => 'Service'],
        ]]]),
    ]);

    $resolver = app(CompanyEmailResolver::class);
    $result = $resolver->resolve('anne@ambiguous.dk');

    expect($result)->toHaveCount(2);
});
