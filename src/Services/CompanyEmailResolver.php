<?php

namespace TheFountainhead\Metis\Services;

class CompanyEmailResolver
{
    public function __construct(protected RegistryApi $api) {}

    public function resolve(string $email): array
    {
        $domain = strtolower(substr($email, strrpos($email, '@') + 1));
        $results = $this->api->searchByName($domain);

        return collect($results)
            ->map(fn ($company) => [
                'cvr' => $company['cvr'],
                'name' => $company['name'],
                'industry' => $company['industry'] ?? null,
            ])
            ->values()
            ->all();
    }
}
