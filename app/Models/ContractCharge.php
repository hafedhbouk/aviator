<?php

namespace App\Models;

use App\Enums\ChargeNature;
use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Frais figé au retour d'un contrat (D-09) : nature, quantité, prix unitaire, montant HT
 * et détail du calcul avec les paramètres utilisés. Ajout seul (un paramètre modifié
 * ensuite ne le change pas) ; seul le rattachement au dossier de dommage (lot L9) peut
 * être posé ensuite. Écrit uniquement par App\Domain\Rental.
 *
 * @property int $id
 * @property int $contract_id
 * @property ChargeNature $nature
 * @property string $label
 * @property string $quantity
 * @property Money $unit_amount
 * @property Money $amount
 * @property array<string, mixed> $calculation
 * @property int|null $inspection_damage_id
 * @property int|null $damage_file_id
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
class ContractCharge extends Model
{
    use AuditsChanges;

    private const MUTABLE = ['damage_file_id', 'updated_at'];

    protected $fillable = [
        'contract_id', 'nature', 'label', 'quantity', 'unit_amount', 'amount', 'calculation',
        'inspection_damage_id', 'damage_file_id', 'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (ContractCharge $charge): void {
            if (array_diff(array_keys($charge->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Un frais figé au retour ne se modifie pas.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Un frais figé au retour ne se supprime pas.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'nature' => ChargeNature::class,
            'unit_amount' => MoneyCast::class,
            'amount' => MoneyCast::class,
            'calculation' => 'array',
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
