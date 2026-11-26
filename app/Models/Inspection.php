<?php

namespace App\Models;

use App\Enums\InspectionType;
use App\Support\Audit\AuditsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * État des lieux de départ ou de retour d'un contrat (D-06). Il appartient à l'agence
 * de son contrat : il se lit et s'écrit à travers lui (ContractPolicy), uniquement par
 * App\Domain\Rental. Validé, il n'est plus modifiable (seul l'exemplaire signé en PDF
 * peut y être rattaché, lot L11) et ne se supprime jamais.
 *
 * @property int $id
 * @property int $contract_id
 * @property InspectionType $type
 * @property int|null $mileage
 * @property int|null $fuel_eighths
 * @property string|null $notes
 * @property string|null $signature_path
 * @property string|null $signature_sha256
 * @property string|null $customer_absent_reason
 * @property CarbonImmutable|null $returned_at_declared
 * @property string|null $signed_pdf_path
 * @property string|null $signed_pdf_sha256
 * @property CarbonImmutable|null $validated_at
 * @property int|null $validated_by
 * @property int|null $created_by
 */
class Inspection extends Model
{
    use AuditsChanges;

    /** Colonnes encore modifiables après la validation. */
    private const AFTER_VALIDATION = ['signed_pdf_path', 'signed_pdf_sha256', 'updated_at'];

    protected $fillable = [
        'contract_id', 'type', 'mileage', 'fuel_eighths', 'notes', 'signature_path', 'signature_sha256',
        'customer_absent_reason', 'returned_at_declared', 'signed_pdf_path', 'signed_pdf_sha256',
        'validated_at', 'validated_by', 'created_by',
    ];

    protected static function booted(): void
    {
        static::updating(function (Inspection $inspection): void {
            if ($inspection->getOriginal('validated_at') !== null
                && array_diff(array_keys($inspection->getDirty()), self::AFTER_VALIDATION) !== []) {
                throw new LogicException('Un état des lieux validé ne se modifie pas.');
            }
        });

        static::deleting(function (): never {
            throw new LogicException('Un état des lieux ne se supprime pas.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => InspectionType::class,
            'mileage' => 'integer',
            'fuel_eighths' => 'integer',
            'returned_at_declared' => 'immutable_datetime',
            'validated_at' => 'immutable_datetime',
        ];
    }

    public function isValidated(): bool
    {
        return $this->validated_at !== null;
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return HasMany<InspectionDamage, $this>
     */
    public function damages(): HasMany
    {
        return $this->hasMany(InspectionDamage::class)->orderBy('id');
    }

    /**
     * @return HasMany<InspectionPhoto, $this>
     */
    public function photos(): HasMany
    {
        return $this->hasMany(InspectionPhoto::class)->orderBy('id');
    }
}
