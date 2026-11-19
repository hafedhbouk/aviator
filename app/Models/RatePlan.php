<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Grille tarifaire (A-04) d'une catégorie pour une saison : commune aux agences
 * (agency_id nul) ou propre à une agence (Q10). Validité appliquée au jour loué.
 * Modifiée uniquement par App\Domain\Setup\Actions\SaveRatePlan ; jamais supprimée.
 *
 * @property int $id
 * @property int $vehicle_category_id
 * @property int $season_id
 * @property int|null $agency_id
 * @property CarbonImmutable $valid_from
 * @property CarbonImmutable|null $valid_to
 * @property bool $is_active
 */
#[Fillable(['vehicle_category_id', 'season_id', 'agency_id', 'valid_from', 'valid_to', 'is_active'])]
class RatePlan extends Model
{
    use AuditsChanges;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'valid_from' => 'immutable_date',
            'valid_to' => 'immutable_date',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<VehicleCategory, $this>
     */
    public function vehicleCategory(): BelongsTo
    {
        return $this->belongsTo(VehicleCategory::class);
    }

    /**
     * @return BelongsTo<Season, $this>
     */
    public function season(): BelongsTo
    {
        return $this->belongsTo(Season::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * Tranches de durée, de la plus courte à la plus longue.
     *
     * @return HasMany<RatePlanLine, $this>
     */
    public function lines(): HasMany
    {
        return $this->hasMany(RatePlanLine::class)->orderBy('min_days');
    }
}
