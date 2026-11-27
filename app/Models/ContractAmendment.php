<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Avenant à un contrat clôturé (D-11) : type (`note` ou `additional_driver`), motif,
 * changements figés. Ajout seul ; jamais modifié ni supprimé. Écrit uniquement par
 * App\Domain\Rental\Actions\AmendContract.
 *
 * @property int $id
 * @property int $contract_id
 * @property string $type
 * @property string $reason
 * @property array<string, mixed> $changes
 * @property int|null $created_by
 * @property CarbonImmutable|null $created_at
 */
class ContractAmendment extends Model
{
    use AuditsChanges;

    public const NOTE = 'note';

    public const ADDITIONAL_DRIVER = 'additional_driver';

    protected $fillable = ['contract_id', 'type', 'reason', 'changes', 'created_by'];

    protected static function booted(): void
    {
        static::updating(function (): never {
            throw new LogicException('Un avenant ne se modifie pas.');
        });

        static::deleting(function (): never {
            throw new LogicException('Un avenant ne se supprime pas.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['changes' => 'array', 'created_at' => 'immutable_datetime'];
    }

    public function typeLabel(): string
    {
        return $this->type === self::ADDITIONAL_DRIVER ? 'Conducteur additionnel' : 'Mention corrective';
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }
}
