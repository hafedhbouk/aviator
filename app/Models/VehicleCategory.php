<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Database\Factories\VehicleCategoryFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Catégorie de véhicules (A-03), commune aux 4 agences. Modifiée uniquement par
 * App\Domain\Setup\Actions\SaveVehicleCategory ; jamais supprimée (désactivation).
 * Valeurs effectives (surcharge ou valeur générale) : App\Domain\Setup\CategoryRules.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $description
 * @property Money|null $deposit_amount
 * @property int|null $min_driver_age
 * @property int|null $min_license_years
 * @property int|null $included_km_per_day
 * @property Money|null $extra_km_amount
 * @property Money|null $insurance_excess_amount
 * @property int|null $preparation_buffer_minutes
 * @property int $sort_order
 * @property bool $is_active
 */
#[Fillable([
    'code', 'name', 'description', 'deposit_amount', 'min_driver_age', 'min_license_years',
    'included_km_per_day', 'extra_km_amount', 'insurance_excess_amount', 'preparation_buffer_minutes',
    'sort_order', 'is_active',
])]
class VehicleCategory extends Model
{
    use AuditsChanges;

    /** @use HasFactory<VehicleCategoryFactory> */
    use HasFactory;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'deposit_amount' => MoneyCast::class,
            'extra_km_amount' => MoneyCast::class,
            'insurance_excess_amount' => MoneyCast::class,
            'min_driver_age' => 'integer',
            'min_license_years' => 'integer',
            'included_km_per_day' => 'integer',
            'preparation_buffer_minutes' => 'integer',
            'sort_order' => 'integer',
            'is_active' => 'boolean',
        ];
    }
}
