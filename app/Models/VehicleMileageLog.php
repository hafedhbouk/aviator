<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Relevé de kilométrage d'un véhicule (B-03), en ajout seul : ni modification ni
 * suppression. Écrit uniquement par App\Domain\Fleet\MileageLedger.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property int $previous_mileage
 * @property int $mileage
 * @property string|null $source_type
 * @property int|null $source_id
 * @property bool $is_correction
 * @property string|null $reason
 * @property int|null $user_id
 * @property CarbonImmutable $recorded_at
 */
class VehicleMileageLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['vehicle_id', 'previous_mileage', 'mileage', 'source_type', 'source_id', 'is_correction', 'reason', 'user_id', 'recorded_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Relevés de kilométrage en ajout seul : aucune modification.'));
        static::deleting(fn () => throw new LogicException('Relevés de kilométrage en ajout seul : aucune suppression.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'previous_mileage' => 'integer',
            'mileage' => 'integer',
            'is_correction' => 'boolean',
            'recorded_at' => 'immutable_datetime',
        ];
    }

    /**
     * @return BelongsTo<Vehicle, $this>
     */
    public function vehicle(): BelongsTo
    {
        return $this->belongsTo(Vehicle::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return MorphTo<Model, $this>
     */
    public function source(): MorphTo
    {
        return $this->morphTo();
    }
}
