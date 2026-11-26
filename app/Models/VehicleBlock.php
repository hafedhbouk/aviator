<?php

namespace App\Models;

use App\Domain\Availability\BlockRegistry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Période d'occupation d'un véhicule (D1, RG-01) : fin délai de préparation compris.
 * Écrite uniquement par App\Domain\Availability\BlockRegistry, sous verrou de la
 * ligne du véhicule ; jamais supprimée (libérée : active = false). Sans portée par
 * agence : la disponibilité se lit par AvailabilityQuery, sans donnée client.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $agency_id
 * @property CarbonImmutable $starts_at
 * @property CarbonImmutable $ends_at
 * @property int $buffer_minutes
 * @property string $source_type
 * @property int $source_id
 * @property bool $active
 * @property CarbonImmutable|null $released_at
 */
class VehicleBlock extends Model
{
    protected $fillable = ['vehicle_id', 'agency_id', 'starts_at', 'ends_at', 'buffer_minutes', 'source_type', 'source_id', 'active', 'released_at'];

    protected static function booted(): void
    {
        static::saving(function (): void {
            if (! BlockRegistry::isWriting()) {
                throw new LogicException('Une occupation de véhicule ne s\'écrit que par BlockRegistry.');
            }
        });

        static::deleting(fn () => throw new LogicException('Une occupation ne se supprime pas : elle se libère.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_at' => 'immutable_datetime',
            'ends_at' => 'immutable_datetime',
            'buffer_minutes' => 'integer',
            'active' => 'boolean',
            'released_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class)->withoutGlobalScopes();
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
