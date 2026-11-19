<?php

namespace App\Models;

use App\Enums\FiscalValidationStatus;
use App\Support\Audit\AuditsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Version datée d'une règle de TVA pour un code fiscal. **Jamais modifiée sur place** :
 * seules la fin de validité (clôture par une nouvelle version) et l'état actif
 * changent, par les actions de App\Domain\Tax. Jamais supprimée.
 *
 * @property int $id
 * @property int $tax_code_id
 * @property string|null $rate Taux en pourcentage, « 19.0000 » ; nul si non taxable
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property FiscalValidationStatus $validation_status
 * @property int|null $fiscal_reference_id
 * @property bool $is_active
 * @property int|null $created_by
 */
#[Fillable(['tax_code_id', 'rate', 'valid_from', 'valid_to', 'validation_status', 'fiscal_reference_id', 'is_active', 'created_by'])]
class TaxRule extends Model
{
    use AuditsChanges;

    /** Colonnes qu'une modification peut toucher. */
    public const MUTABLE = ['valid_to', 'is_active', 'updated_at'];

    protected static function booted(): void
    {
        static::updating(function (TaxRule $rule): void {
            if (array_diff(array_keys($rule->getDirty()), self::MUTABLE) !== []) {
                throw new LogicException('Une règle fiscale ne se modifie pas sur place : créez une nouvelle version.');
            }
        });
        static::deleting(fn () => throw new LogicException('Une règle fiscale ne se supprime jamais.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'validation_status' => FiscalValidationStatus::class,
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<TaxCode, $this>
     */
    public function taxCode(): BelongsTo
    {
        return $this->belongsTo(TaxCode::class);
    }

    /**
     * @return BelongsTo<FiscalReference, $this>
     */
    public function fiscalReference(): BelongsTo
    {
        return $this->belongsTo(FiscalReference::class);
    }
}
