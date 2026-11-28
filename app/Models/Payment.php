<?php

namespace App\Models;

use App\Support\Agency\BelongsToAgency;
use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use App\Support\Money\UnsupportedCurrencyException;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Encaissement (E-06, E17, D37, D57) de l'agence qui le reçoit (RG-13). En dinars
 * seulement (D26). Jamais supprimé (RG-11) : il s'annule avec motif, et ses imputations
 * ne comptent plus. Seuls l'annulation et le rattachement à la caisse (L8) le modifient.
 * Écrit uniquement par App\Domain\Billing.
 *
 * @property int $id
 * @property int $agency_id
 * @property int $customer_id
 * @property int|null $reservation_id
 * @property int $payment_method_id
 * @property string|null $reference
 * @property string|null $bank_name
 * @property Money $amount
 * @property Money $allocated_amount cumul des imputations, tenu sous verrou
 * @property string $currency
 * @property CarbonImmutable $received_at
 * @property int|null $cash_session_id
 * @property string $status « recorded » ou « cancelled »
 * @property string|null $cancellation_reason
 * @property CarbonImmutable|null $cancelled_at
 * @property int|null $cancelled_by
 * @property int|null $created_by
 * @property-read PaymentMethod $paymentMethod
 * @property-read Customer $customer
 */
#[Fillable([
    'agency_id', 'customer_id', 'reservation_id', 'payment_method_id', 'reference', 'bank_name', 'amount', 'allocated_amount', 'currency',
    'received_at', 'cash_session_id', 'status', 'cancellation_reason', 'cancelled_at', 'cancelled_by', 'created_by',
])]
class Payment extends Model
{
    use AuditsChanges;
    use BelongsToAgency;

    public const RECORDED = 'recorded';

    public const CANCELLED = 'cancelled';

    /** Colonnes modifiables après l'enregistrement : cumul des imputations, annulation, caisse (L8). */
    private const MUTABLE = ['allocated_amount', 'status', 'cancellation_reason', 'cancelled_at', 'cancelled_by', 'cash_session_id', 'updated_at'];

    protected $attributes = [
        'currency' => Money::CURRENCY,
        'status' => self::RECORDED,
    ];

    protected static function booted(): void
    {
        static::saving(fn (Payment $payment) => UnsupportedCurrencyException::assertTnd($payment->currency));

        static::updating(function (Payment $payment): void {
            if ($payment->getOriginal('status') === self::CANCELLED
                || array_diff(array_keys($payment->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Un encaissement enregistré ne se modifie pas : il s\'annule avec un motif.');
            }
        });

        static::deleting(fn () => throw new LogicException('Un encaissement ne se supprime pas : il s\'annule avec un motif (RG-11).'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'allocated_amount' => MoneyCast::class,
            'received_at' => 'immutable_datetime',
            'cancelled_at' => 'immutable_datetime',
        ];
    }

    public function isCancelled(): bool
    {
        return $this->status === self::CANCELLED;
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
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
        return $this->belongsTo(Reservation::class);
    }

    /**
     * @return HasMany<PaymentAllocation, $this>
     */
    public function allocations(): HasMany
    {
        return $this->hasMany(PaymentAllocation::class);
    }
}
