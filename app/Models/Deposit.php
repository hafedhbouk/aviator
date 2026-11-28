<?php

namespace App\Models;

use App\Enums\ContractStatus;
use App\Enums\DepositStatus;
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
 * Caution d'un contrat (module F, RG-08, D58) : une par contrat, en dinars (D26),
 * jamais en chiffre d'affaires (F-07). Seuls ses cumuls restitué et retenu changent, sous
 * son verrou (App\Domain\Billing\Deposits) ; jamais supprimée. Aucune donnée de carte :
 * la référence d'une empreinte est celle de l'autorisation (Q54).
 *
 * @property int $id
 * @property int $agency_id
 * @property int $contract_id
 * @property int $customer_id
 * @property Money|null $expected_amount
 * @property Money $amount_received
 * @property string|null $difference_reason
 * @property string $currency
 * @property int $payment_method_id
 * @property string|null $reference
 * @property string|null $bank_name
 * @property CarbonImmutable $received_at
 * @property int|null $cash_session_id
 * @property string $receipt_number
 * @property string $receipt_sequence_key
 * @property Money $refunded_amount
 * @property Money $retained_amount
 * @property int|null $created_by
 * @property-read Contract $contract
 * @property-read PaymentMethod $paymentMethod
 */
#[Fillable([
    'agency_id', 'contract_id', 'customer_id', 'expected_amount', 'amount_received', 'difference_reason', 'currency',
    'payment_method_id', 'reference', 'bank_name', 'received_at', 'cash_session_id', 'receipt_number', 'receipt_sequence_key',
    'refunded_amount', 'retained_amount', 'created_by',
])]
class Deposit extends Model
{
    use AuditsChanges;
    use BelongsToAgency;

    private const MUTABLE = ['refunded_amount', 'retained_amount', 'updated_at'];

    protected $attributes = [
        'currency' => Money::CURRENCY,
        'refunded_amount' => '0.000',
        'retained_amount' => '0.000',
    ];

    protected static function booted(): void
    {
        static::saving(fn (Deposit $deposit) => UnsupportedCurrencyException::assertTnd($deposit->currency));

        static::updating(function (Deposit $deposit): void {
            if (array_diff(array_keys($deposit->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Une caution enregistrée ne se modifie pas : seuls ses restitutions et retenues s\'y ajoutent.');
            }
        });

        static::deleting(fn () => throw new LogicException('Une caution ne se supprime pas.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expected_amount' => MoneyCast::class,
            'amount_received' => MoneyCast::class,
            'received_at' => 'immutable_datetime',
            'refunded_amount' => MoneyCast::class,
            'retained_amount' => MoneyCast::class,
        ];
    }

    /** Reste à restituer ou retenir (RG-08 : reçu − restitué − retenu). */
    public function remaining(): Money
    {
        return $this->amount_received->minus($this->refunded_amount)->minus($this->retained_amount);
    }

    public function status(): DepositStatus
    {
        if ($this->remaining()->isPositive()) {
            $contractStatus = Contract::query()->withoutGlobalScopes()->whereKey($this->contract_id)->value('status');
            $returned = $contractStatus === ContractStatus::Returned || $contractStatus === ContractStatus::Closed;

            return $returned ? DepositStatus::ToRefund : DepositStatus::Received;
        }

        return $this->retained_amount->isPositive() ? DepositStatus::PartiallyOffset : DepositStatus::Refunded;
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return BelongsTo<PaymentMethod, $this>
     */
    public function paymentMethod(): BelongsTo
    {
        return $this->belongsTo(PaymentMethod::class);
    }

    /**
     * @return HasMany<DepositMovement, $this>
     */
    public function movements(): HasMany
    {
        return $this->hasMany(DepositMovement::class);
    }
}
