<?php

namespace Database\Factories;

use App\Enums\CustomerType;
use App\Models\Agency;
use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Clients fictifs pour les tests (aucune donnée réelle).
 *
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'type' => CustomerType::Individual,
            'first_name' => fake()->firstName(),
            'last_name' => fake()->lastName(),
            'phone' => '+216 '.fake()->numerify('## ### ###'),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->streetAddress(),
            'city' => fake()->city(),
            'country' => 'TN',
            'id_document_type' => 'cin',
            'id_document_number' => fake()->unique()->numerify('########'),
            'origin_agency_id' => Agency::factory(),
        ];
    }

    public function company(): static
    {
        return $this->state(fn (): array => [
            'type' => CustomerType::Company,
            'first_name' => null,
            'last_name' => null,
            'company_name' => fake()->company(),
            'tax_id' => fake()->numerify('#######').'/A/M/000',
        ]);
    }
}
