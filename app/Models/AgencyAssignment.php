<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Affectation de remplacement d'un utilisateur d'agence à une autre agence, entre
 * deux dates incluses, appréciées dans le fuseau Africa/Tunis (Q69).
 *
 * @property int $id
 * @property int $user_id
 * @property int $agency_id
 * @property CarbonImmutable $starts_on
 * @property CarbonImmutable $ends_on
 * @property int|null $created_by
 */
#[Fillable(['user_id', 'agency_id', 'starts_on', 'ends_on', 'created_by'])]
class AgencyAssignment extends Model
{
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
     * Date du jour dans le fuseau de l'application (Africa/Tunis), quel que soit le fuseau du serveur.
     */
    public static function today(): string
    {
        return CarbonImmutable::now((string) config('app.timezone'))->toDateString();
    }

    /**
     * Affectations actives à une date (bornes incluses).
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActiveOn(Builder $query, ?CarbonInterface $date = null): Builder
    {
        $day = $date?->copy()->setTimezone((string) config('app.timezone'))->toDateString() ?? self::today();

        return $query->where('starts_on', '<=', $day)->where('ends_on', '>=', $day);
    }

    public function status(): string
    {
        $today = self::today();

        return match (true) {
            $this->starts_on->toDateString() > $today => 'upcoming',
            $this->ends_on->toDateString() < $today => 'ended',
            default => 'active',
        };
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return BelongsTo<Agency, $this>
     */
    public function agency(): BelongsTo
    {
        return $this->belongsTo(Agency::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
