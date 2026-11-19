<?php

namespace App\Models;

use App\Enums\FinancingMode;
use App\Enums\FuelType;
use App\Enums\Transmission;
use App\Enums\VehicleStatus;
use App\Support\Agency\AgencyScope;
use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Database\Factories\VehicleFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Véhicule du parc (B-01 à B-05). Modifié uniquement par
 * App\Domain\Fleet\Actions\SaveVehicle ; jamais supprimé (sortie du parc, lot L5).
 *
 * - `status` n'est pas assignable en masse : seul VehicleStatusService l'écrit (L5).
 * - `mileage` n'est pas assignable en masse : seul RecordMileage l'écrit (L4).
 * - Portée par agence sur la **localisation** (`current_agency_id`) : l'utilisateur
 *   d'agence lit les véhicules qui se trouvent dans ses agences (architecture §8.3).
 *   La disponibilité des autres agences passe par une projection sans détail (L9).
 *
 * @property int $id
 * @property string $internal_code
 * @property string $plate_number
 * @property string $vin
 * @property int $vehicle_category_id
 * @property string $brand
 * @property string $model
 * @property int $year
 * @property string|null $color
 * @property FuelType $fuel
 * @property Transmission $transmission
 * @property int $agency_id
 * @property int $current_agency_id
 * @property VehicleStatus $status
 * @property int $mileage
 * @property CarbonImmutable|null $acquired_on
 * @property int|null $acquisition_supplier_id
 * @property Money|null $purchase_amount
 * @property FinancingMode $financing_mode
 * @property Money|null $initial_contribution_amount
 * @property int|null $planned_holding_months
 * @property CarbonImmutable|null $retired_on
 * @property string|null $retirement_reason
 * @property Money|null $disposal_amount
 */
#[Fillable([
    'internal_code', 'plate_number', 'vin', 'vehicle_category_id', 'brand', 'model', 'year', 'color',
    'fuel', 'transmission', 'agency_id', 'current_agency_id', 'acquired_on', 'acquisition_supplier_id',
    'purchase_amount', 'financing_mode', 'initial_contribution_amount', 'planned_holding_months',
])]
class Vehicle extends Model
{
    use AuditsChanges;

    /** @use HasFactory<VehicleFactory> */
    use HasFactory;

    protected static function booted(): void
    {
        static::addGlobalScope(new AgencyScope('current_agency_id'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'year' => 'integer',
            'fuel' => FuelType::class,
            'transmission' => Transmission::class,
            'status' => VehicleStatus::class,
            'mileage' => 'integer',
            'acquired_on' => 'immutable_date',
            'purchase_amount' => MoneyCast::class,
            'financing_mode' => FinancingMode::class,
            'initial_contribution_amount' => MoneyCast::class,
            'planned_holding_months' => 'integer',
            'retired_on' => 'immutable_date',
            'disposal_amount' => MoneyCast::class,
        ];
    }

    public function label(): string
    {
        return "{$this->plate_number} · {$this->brand} {$this->model}";
    }

    /**
     * @return BelongsTo<VehicleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id');
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function currentAgency(): BelongsTo
    {
        return $this->belongsTo(Agency::class, 'current_agency_id');
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function acquisitionSupplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class, 'acquisition_supplier_id');
    }

    /**
     * @return HasMany<VehicleDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(VehicleDocument::class);
    }
}
