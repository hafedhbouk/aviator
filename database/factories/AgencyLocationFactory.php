<?php

namespace Database\Factories;

use App\Models\Agency;
use App\Models\AgencyLocation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AgencyLocation>
 */
class AgencyLocationFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'agency_id' => Agency::factory(),
            'name' => fake()->unique()->streetName(),
            'is_pickup' => true,
            'is_return' => true,
            'opening_hours' => null,
            'is_active' => true,
        ];
    }
}
