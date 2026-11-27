<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * Journal d'un changement de format d'une série (D27-3) : avant, après, qui l'a
 * décidé, qui l'a exécuté, quand. Jamais modifié ni supprimé.
 *
 * @property int $id
 * @property int $sequence_series_id
 * @property array{prefix: string, template: string, padding: int, reset_period: string, example: string} $before
 * @property array{prefix: string, template: string, padding: int, reset_period: string, example: string} $after
 * @property string $decided_by
 * @property int|null $confirmation_reference_id
 * @property string|null $operator
 * @property string|null $host
 * @property CarbonImmutable $created_at
 * @property-read SequenceSeries $series
 */
#[Fillable(['sequence_series_id', 'before', 'after', 'decided_by', 'confirmation_reference_id', 'operator', 'host'])]
class SequenceSeriesChange extends Model
{
    public const UPDATED_AT = null;

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Le journal de la numérotation ne se modifie jamais.'));
        static::deleting(fn () => throw new LogicException('Le journal de la numérotation ne se supprime jamais.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'before' => 'array',
            'after' => 'array',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<SequenceSeries, $this>
     */
    public function series(): BelongsTo
    {
        return $this->belongsTo(SequenceSeries::class, 'sequence_series_id');
    }
}
