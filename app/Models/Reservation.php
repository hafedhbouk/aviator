<?php

namespace App\Models;

use App\Enums\ReservationStatus;
use App\Support\Agency\BelongsToAgency;
use App\Support\Audit\AuditsChanges;
use App\Support\Documents\HasSequenceNumber;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Réservation (module C). Document de l'agence de départ (RG-13, BelongsToAgency),
 * numéroté `RES-AAAA-NNNNN` (Q49) et jamais supprimé (HasSequenceNumber, RG-11) :
 * il s'annule. Écrit uniquement par les actions de App\Domain\Availability\Actions,
 * sous verrou de la catégorie et du véhicule (RG-01, RG-02, Q36), et par
 * App\Domain\Rental\Actions\OpenContract pour sa conversion en contrat.
 *
 * @property int $id
 * @property string $number
 * @property int $agency_id
 * @property int $return_agency_id
 * @property int|null $pickup_location_id
 * @property int|null $return_location_id
 * @property int $customer_id
 * @property int $vehicle_category_id
 * @property int|null $vehicle_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property ReservationStatus $status
 * @property string $channel
 * @property array<string, mixed> $quote
 * @property Money $estimated_total_amount
 * @property Money $discount_amount
 * @property int|null $discount_approval_id
 * @property int|null $override_approval_id
 * @property CarbonImmutable|null $expires_at
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
class Reservation extends Model
{
    use AuditsChanges;
    use BelongsToAgency;
    use HasSequenceNumber;

    protected $fillable = [
        'number', 'agency_id', 'return_agency_id', 'pickup_location_id', 'return_location_id', 'customer_id',
        'vehicle_category_id', 'vehicle_id', 'starts_at', 'ends_at', 'status', 'channel', 'quote',
        'estimated_total_amount', 'discount_amount', 'expires_at', 'cancellation_reason', 'cancelled_at', 'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'status' => ReservationStatus::class,
            'quote' => 'array',
            'estimated_total_amount' => MoneyCast::class,
            'discount_amount' => MoneyCast::class,
            'expires_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Customer, $this>
     */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /**
     * @return BelongsTo<VehicleCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class, 'vehicle_category_id');
    }

    /**
     * Véhicule affecté, lu sans portée par agence (il peut être localisé ailleurs).
     *
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes();
    }

    /**
     * @return HasMany<ReservationOption, $this>
     */
    public function options(): HasMany
    {
        return $this->hasMany(ReservationOption::class);
    }

    /**
     * Demande d'approbation de la remise (RG-10, D46).
     *
     * @return BelongsTo<ApprovalRequest, $this>
     */
    public function discountApproval(): BelongsTo
    {
        return $this->belongsTo(ApprovalRequest::class, 'discount_approval_id')->withoutGlobalScopes();
    }

    /**
     * Demande de dérogation à la liste noire (RG-15, D46).
     *
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
