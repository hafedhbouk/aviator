<?php

namespace Database\Factories;

use App\Enums\FinancingMode;
use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Models\Agency;
use App\Models\Vehicle;
use App\Models\VehicleCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Vehicle>
 */
class VehicleFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internal_code' => strtoupper(fake()->unique()->bothify('V-####')),
            'plate_number' => fake()->unique()->numerify('### TU ####'),
            'vin' => strtoupper(fake()->unique()->bothify('VF1##########????')),
            'vehicle_category_id' => VehicleCategory::factory(),
            'brand' => 'Renault',
            'model' => 'Clio',
            'year' => 2024,
            'fuel' => FuelType::Petrol,
            'transmission' => Transmission::Manual,
            'agency_id' => Agency::factory(),
            'current_agency_id' => fn (array $attributes) => $attributes['agency_id'],
            'financing_mode' => FinancingMode::Cash,
        ];
    }
}
