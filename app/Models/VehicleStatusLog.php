<?php

namespace App\Models;

use App\Enums\VehicleStatus;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use LogicException;

/**
 * Changement de statut d'un véhicule (B-09), en ajout seul : ni modification ni
 * suppression. Écrit uniquement par App\Domain\Fleet\VehicleStatusService.
 *
 * @property int $id
 * @property int $vehicle_id
 * @property VehicleStatus|null $from_status
 * @property VehicleStatus $to_status
 * @property string|null $cause_type
 * @property int|null $cause_id
 * @property string|null $reason
 * @property int|null $user_id
 * @property CarbonImmutable $occurred_at
 */
class VehicleStatusLog extends Model
{
    public $timestamps = false;

    protected $fillable = ['vehicle_id', 'from_status', 'to_status', 'cause_type', 'cause_id', 'reason', 'user_id', 'occurred_at'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Historique des statuts en ajout seul : aucune modification.'));
        static::deleting(fn () => throw new LogicException('Historique des statuts en ajout seul : aucune suppression.'));
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'from_status' => VehicleStatus::class,
            'to_status' => VehicleStatus::class,
            'occurred_at' => 'immutable_datetime',
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
    public function cause(): MorphTo
    {
        return $this->morphTo();
    }
}
