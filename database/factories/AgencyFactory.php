<?php

namespace Database\Factories;

use App\Models\Agency;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Agency>
 */
class AgencyFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $city = fake()->unique()->city();

        return [
            'code' => strtoupper(fake()->unique()->lexify('???')),
            'name' => $city,
            'is_head_office' => false,
            'city' => $city,
            'is_active' => true,
        ];
    }

    public function headOffice(): static
    {
        return $this->state(fn (): array => ['is_head_office' => true]);
    }
}
