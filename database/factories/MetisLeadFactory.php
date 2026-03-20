<?php

namespace TheFountainhead\Metis\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use TheFountainhead\Metis\Models\MetisLead;

class MetisLeadFactory extends Factory
{
    protected $model = MetisLead::class;

    public function definition(): array
    {
        return [
            'email' => fake()->unique()->companyEmail(),
            'cvr' => fake()->numerify('########'),
            'company_name' => fake()->company(),
            'industry' => fake()->jobTitle(),
            'domain' => fake()->domainName(),
        ];
    }
}
