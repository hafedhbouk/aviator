<?php

namespace App\Models;

use App\Support\Audit\AuditsChanges;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Saison datée (A-04), bornes incluses, sans chevauchement avec une autre saison.
 * Modifiée par App\Domain\Setup\Actions\SaveSeason ; supprimée seulement si aucune
 * grille ne l'utilise (DeleteSeason).
 *
 * @property int $id
 * @property string $name
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 */
#[Fillable(['name', 'starts_on', 'ends_on'])]
class Season extends Model
{
    use AuditsChanges;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'starts_on' => 'immutable_date',
            'ends_on' => 'immutable_date',
        ];
    }

    /**
     * @return HasMany<RatePlan, $this>
     */
    public function ratePlans(): HasMany
    {
        return $this->hasMany(RatePlan::class);
    }

    public function label(): string
    {
        return "{$this->name} ({$this->starts_on->format('d/m/Y')} – {$this->ends_on->format('d/m/Y')})";
    }
}
