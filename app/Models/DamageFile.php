<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Dossier de dommage (N2) : un par dommage nouveau constaté au retour. Il appartient à
 * l'agence de son contrat (lu et écrit à travers lui, ContractPolicy). Écrit uniquement
 * par App\Domain\Rental ; jamais supprimé ; complet, il n'accepte plus de pièce.
 *
 * @property int $id
 * @property int $contract_id
 * @property int $inspection_damage_id
 * @property string $status « open » ou « complete »
 * @property string|null $notes
 * @property CarbonImmutable|null $completed_at
 * @property int|null $completed_by
 * @property int|null $created_by
 */
class DamageFile extends Model
{
    use AuditsChanges;

    public const OPEN = 'open';

    public const COMPLETE = 'complete';

    protected $fillable = ['contract_id', 'inspection_damage_id', 'status', 'notes', 'completed_at', 'completed_by', 'created_by'];

    protected static function booted(): void
    {
        static::deleting(function (): never {
            throw new LogicException('Un dossier de dommage ne se supprime pas.');
        });
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['completed_at' => 'immutable_datetime'];
    }

    public function isComplete(): bool
    {
        return $this->status === self::COMPLETE;
    }

    /**
     * @return BelongsTo<Contract, $this>
     */
    public function contract(): BelongsTo
    {
        return $this->belongsTo(Contract::class);
    }

    /**
     * @return BelongsTo<InspectionDamage, $this>
     */
    public function damage(): BelongsTo
    {
        return $this->belongsTo(InspectionDamage::class, 'inspection_damage_id');
    }

    /**
     * @return HasMany<DamageFileDocument, $this>
     */
    public function documents(): HasMany
    {
        return $this->hasMany(DamageFileDocument::class)->orderBy('id');
    }
}
