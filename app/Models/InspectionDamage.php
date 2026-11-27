<?php

namespace App\Models;

use App\Enums\DamageSeverity;
use App\Enums\VehicleZone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Dommage placé sur le schéma du véhicule (D-06) : zone, position (x, y en % du
 * schéma), description, gravité. `is_new` faux pour un dommage déjà présent au dernier
 * retour, repris automatiquement (`carried_from_id`).
 *
 * @property int $id
 * @property int $inspection_id
 * @property VehicleZone $zone
 * @property string $x
 * @property string $y
 * @property string $description
 * @property DamageSeverity $severity
 * @property bool $is_new
 * @property int|null $carried_from_id
 */
class InspectionDamage extends Model
{
    protected $fillable = ['inspection_id', 'zone', 'x', 'y', 'description', 'severity', 'is_new', 'carried_from_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'zone' => VehicleZone::class,
            'severity' => DamageSeverity::class,
            'is_new' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<Inspection, $this>
     */
    public function inspection(): BelongsTo
    {
        return $this->belongsTo(Inspection::class);
    }
}
