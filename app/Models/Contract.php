<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Support\Agency\BelongsToAgency;
use App\Support\Audit\AuditsChanges;
use App\Support\Documents\HasSequenceNumber;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Contrat de location (module D). Document de l'agence de départ (RG-13,
 * BelongsToAgency), numéroté `CTR-AAAA-NNNNN` (Q49) et jamais supprimé
 * (HasSequenceNumber, RG-11) : il s'annule depuis le brouillon. Écrit uniquement par
 * les actions de App\Domain\Rental, sous verrou de la catégorie et du véhicule.
 *
 * Le devis (`quote`), les options, le tarif journalier et les conditions de
 * kilométrage et de carburant sont figés à la création (D-05, D48).
 *
 * @property int $id
 * @property string $number
 * @property int $agency_id
 * @property int $return_agency_id
 * @property int|null $reservation_id
 * @property int $vehicle_id
 * @property int $vehicle_category_id
 * @property int $customer_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property CarbonImmutable|null $departed_at
 * @property CarbonImmutable|null $returned_at
 * @property ContractStatus $status
 * @property string $mileage_policy
 * @property int|null $included_km_per_day
 * @property Money|null $extra_km_amount
 * @property string $fuel_policy
 * @property int|null $fuel_eighths_required
 * @property int|null $fuel_tank_liters
 * @property Money|null $fuel_liter_amount
 * @property Money|null $fuel_service_amount
 * @property Money|null $late_hour_amount
 * @property Money|null $insurance_excess_amount
 * @property Money $daily_rate_amount
 * @property Money $rental_amount
 * @property Money $options_amount
 * @property Money $discount_amount
 * @property Money $total_amount
 * @property array<string, mixed> $quote
 * @property Money|null $deposit_expected_amount
 * @property int|null $discount_approval_id
 * @property int|null $override_approval_id
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property CarbonImmutable|null $closed_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
class Contract extends Model
{
    use AuditsChanges;
    use BelongsToAgency;
    use HasSequenceNumber;

    public const MILEAGE_UNLIMITED = 'unlimited';

    public const MILEAGE_DAILY_ALLOWANCE = 'daily_allowance';

    /** Carburant : même niveau au retour qu'au départ (Q44). */
    public const FUEL_SAME_LEVEL = 'same_level';

    protected static function booted(): void
    {
        // Contrat clôturé non modifiable (D-11) : une correction passe par un avenant.
        static::updating(function (Contract $contract): void {
            if ($contract->getOriginal('status') === ContractStatus::Closed
                && array_diff(array_keys($contract->getDirty()), ['updated_at']) !== []) {
                throw new LogicException('Un contrat clôturé ne se modifie pas : passez par un avenant.');
            }
        });
    }

    protected $fillable = [
        'number', 'agency_id', 'return_agency_id', 'reservation_id', 'vehicle_id', 'vehicle_category_id', 'customer_id',
        'starts_at', 'ends_at', 'departed_at', 'returned_at', 'status', 'mileage_policy', 'included_km_per_day',
        'extra_km_amount', 'fuel_policy', 'fuel_eighths_required', 'fuel_tank_liters', 'fuel_liter_amount', 'fuel_service_amount',
        'daily_rate_amount', 'late_hour_amount', 'insurance_excess_amount', 'rental_amount', 'options_amount',
        'discount_amount', 'total_amount', 'quote', 'deposit_expected_amount', 'discount_approval_id', 'override_approval_id',
        'cancellation_reason', 'cancelled_at', 'closed_at', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'departed_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
            'status' => ContractStatus::class,
            'included_km_per_day' => 'integer',
            'extra_km_amount' => MoneyCast::class,
            'fuel_eighths_required' => 'integer',
            'fuel_tank_liters' => 'integer',
            'fuel_liter_amount' => MoneyCast::class,
            'fuel_service_amount' => MoneyCast::class,
            'late_hour_amount' => MoneyCast::class,
            'insurance_excess_amount' => MoneyCast::class,
            'daily_rate_amount' => MoneyCast::class,
            'rental_amount' => MoneyCast::class,
            'options_amount' => MoneyCast::class,
            'discount_amount' => MoneyCast::class,
            'total_amount' => MoneyCast::class,
            'quote' => 'array',
            'deposit_expected_amount' => MoneyCast::class,
            'cancelled_at' => 'immutable_datetime',
            'closed_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
        ];
    }

    public function hasUnlimitedMileage(): bool
    {
        return $this->mileage_policy === self::MILEAGE_UNLIMITED;
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<Reservation, $this>
     */
    public function reservation(): BelongsTo
    {
        return $this->belongsTo(Reservation::class)->withoutGlobalScopes();
    }

    /**
     * Véhicule loué, lu sans portée par agence (il peut être localisé ailleurs).
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<VehicleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id');
    }

    /**
     * @return HasMany<ContractOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ContractOption::class);
    }

    /**
     * Conducteurs autorisés (D-02), avec le conducteur principal et le résultat des
     * contrôles du permis au moment de l'ajout (`checks`).
     *
     * @return BelongsToMany<Driver, $this>
     */
    public function drivers(): BelongsToMany
    {
        return $this->belongsToMany(Driver::class, 'contract_drivers')->withPivot(['is_primary', 'checks'])->withTimestamps();
    }

    /**
     * Avenants (D-11), en ajout seul.
     *
     * @return HasMany<ContractAmendment, $this>
     */
    public function amendments(): HasMany
    {
        return $this->hasMany(ContractAmendment::class)->orderBy('id');
    }

    /**
     * Dossiers de dommage (N2).
     *
     * @return HasMany<DamageFile, $this>
     */
    public function damageFiles(): HasMany
    {
        return $this->hasMany(DamageFile::class)->orderBy('id');
    }

    /**
     * Frais figés au retour (D-09).
     *
     * @return HasMany<ContractCharge, $this>
     */
    public function charges(): HasMany
    {
        return $this->hasMany(ContractCharge::class)->orderBy('id');
    }

    /**
     * @return HasMany<ContractExtension, $this>
     */
    public function extensions(): HasMany
    {
        return $this->hasMany(ContractExtension::class)->orderBy('id');
    }

    /**
     * @return HasMany<Inspection, $this>
     */
    public function inspections(): HasMany
    {
        return $this->hasMany(Inspection::class);
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function discountApproval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'discount_approval_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function overrideApproval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'override_approval_id')->withoutGlobalScopes();
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
