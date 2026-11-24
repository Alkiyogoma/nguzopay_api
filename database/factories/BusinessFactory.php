<?php

namespace Database\Factories;

use App\Domain\Business\Enums\BusinessType;
use App\Domain\Business\Models\Business;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Business> */
class BusinessFactory extends Factory
{
    protected $model = Business::class;

    public function definition(): array
    {
        return [
            'code' => fake()->unique()->numerify('9###'),
            'type' => BusinessType::School,
            'legal_name' => fake()->company().' Schools Ltd',
            'registration_authority' => 'brela',
            'registration_number' => fake()->numerify('#########'),
            'tin' => fake()->numerify('#########'),
            'email' => fake()->companyEmail(),
            'phone' => '2557'.fake()->numerify('########'),
            'region' => 'Dar es Salaam',
            'physical_address' => 'Plot 12, Mikocheni',
        ];
    }
}
