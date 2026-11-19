<?php

namespace App\Models;

use App\Enums\FiscalValidationStatus;
use App\Support\Audit\AuditsChanges;
use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Règle de timbre fiscal (référentiel v1.0, §3.4) : règle de base de 1,000 DT par
 * facture ordinaire concernée (STANDARD) et exonérations de l'article 118, inactives
 * tant que leur pièce manque. Jamais modifiée sur place ni supprimée.
 *
 * @property int $id
 * @property string $rule_code
 * @property string $description
 * @property Money $amount
 * @property string $applicability
 * @property string|null $exemption_condition
 * @property string $legal_reference
 * @property CarbonImmutable $effective_from
 * @property CarbonImmutable|null $effective_to
 * @property FiscalValidationStatus $validation_status
 * @property int|null $fiscal_reference_id
 * @property bool $is_active
 * @property int|null $created_by
 */
#[Fillable([
    'rule_code', 'description', 'amount', 'applicability', 'exemption_condition', 'legal_reference',
    'effective_from', 'effective_to', 'validation_status', 'fiscal_reference_id', 'is_active', 'created_by',
])]
class StampDutyRule extends Model
{
    use AuditsChanges;

    /** Timbre de base, par facture ordinaire concernée. */
    public const STANDARD = 'STANDARD';

    public const MUTABLE = ['effective_to', 'is_active', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (StampDutyRule $rule): void {
            if (array_diff(array_keys($rule->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Une règle de timbre ne se modifie pas sur place : créez une nouvelle version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Une règle de timbre ne se supprime jamais.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'amount' => MoneyCast::class,
            'effective_from' => 'immutable_date',
            'effective_to' => 'immutable_date',
            'validation_status' => FiscalValidationStatus::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<FiscalReference, $this>
     */
    public function fiscalReference(): BelongsTo
    {
        return $this->belongsTo(FiscalReference::class);
    }
}
