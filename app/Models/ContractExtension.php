<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Prolongation d'un contrat (D-07, D48) : trace du contrôle de disponibilité et du prix
 * ajouté. Ajout seul : seule la remise peut être retirée après un refus du super admin
 * (D46) ; jamais de suppression. Écrite uniquement par App\Domain\Rental.
 *
 * @property int $id
 * @property int $contract_id
 * @property CarbonImmutable $previous_ends_at
 * @property CarbonImmutable $new_ends_at
 * @property int $added_days
 * @property Money $daily_rate_amount
 * @property Money $added_rental_amount
 * @property Money $added_options_amount
 * @property Money $discount_amount
 * @property int|null $discount_approval_id
 * @property Money $total_amount
 * @property list<array{option_id: int, label: string, quantity: int, unit_amount: string, amount: string}>|null $options
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
class ContractExtension extends Model
{
    use AuditsChanges;

    /** Seules colonnes modifiables : retrait de la remise refusée. */
    private const MUTABLE = ['discount_amount', 'discount_approval_id', 'total_amount', 'updated_at'];

    protected $fillable = [
        'contract_id', 'previous_ends_at', 'new_ends_at', 'added_days', 'daily_rate_amount', 'added_rental_amount',
        'added_options_amount', 'discount_amount', 'discount_approval_id', 'total_amount', 'options', 'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (ContractExtension $extension): void {
            if (array_diff(array_keys($extension->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Une prolongation ne se modifie pas.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Une prolongation ne se supprime pas.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_ends_at' => 'immutable_datetime',
            'new_ends_at' => 'immutable_datetime',
            'added_days' => 'integer',
            'daily_rate_amount' => MoneyCast::class,
            'added_rental_amount' => MoneyCast::class,
            'added_options_amount' => MoneyCast::class,
            'discount_amount' => MoneyCast::class,
            'total_amount' => MoneyCast::class,
            'options' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
