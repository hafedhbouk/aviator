<?php

namespace App\Models;

use App\Enums\FinancingOutcome;
use App\Enums\FinancingType;
use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Leasing ou crédit d'un véhicule (B-11, B-14, B-15). Réservé au super admin et à
 * l'admin (portée par profil, architecture §8.3). Écrit uniquement par les actions de
 * App\Domain\Fleet\Financing ; jamais supprimé.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property FinancingType $type
 * @property int $supplier_id
 * @property string $reference
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property CarbonImmutable $first_due_on
 * @property int $installments_count
 * @property Money|null $initial_payment_amount
 * @property Money $monthly_amount_excl_tax
 * @property Money $monthly_amount_incl_tax
 * @property Money|null $residual_value_amount
 * @property Money|null $principal_amount
 * @property string|null $interest_rate
 * @property CarbonImmutable|null $purchase_option_on
 * @property FinancingOutcome|null $outcome
 * @property CarbonImmutable|null $outcome_on
 * @property int|null $created_by
 */
class VehicleFinancing extends Model
{
    use AuditsChanges;

    protected $fillable = [
        'vehicle_id', 'type', 'supplier_id', 'reference', 'starts_on', 'ends_on', 'first_due_on', 'installments_count',
        'initial_payment_amount', 'monthly_amount_excl_tax', 'monthly_amount_incl_tax', 'residual_value_amount',
        'principal_amount', 'interest_rate', 'purchase_option_on', 'outcome', 'outcome_on', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => FinancingType::class,
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
            'first_due_on' => 'immutable_date',
            'installments_count' => 'integer',
            'initial_payment_amount' => MoneyCast::class,
            'monthly_amount_excl_tax' => MoneyCast::class,
            'monthly_amount_incl_tax' => MoneyCast::class,
            'residual_value_amount' => MoneyCast::class,
            'principal_amount' => MoneyCast::class,
            'purchase_option_on' => 'immutable_date',
            'outcome' => FinancingOutcome::class,
            'outcome_on' => 'immutable_date',
        ];
    }

    public function isOpen(): bool
    {
        return $this->outcome === null;
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<Supplier, $this>
     */
    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }

    /**
     * @return HasMany<FinancingInstallment, $this>
     */
    public function installments(): HasMany
    {
        return $this->hasMany(FinancingInstallment::class)->orderBy('sequence');
    }
}
