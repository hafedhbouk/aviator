<?php

namespace App\Models;

use App\Support\Money\Money;
use App\Support\Money\MoneyCast;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Tranche de durée d'une grille : de min_days à max_days jours (max_days nul = sans
 * limite), prix journalier HT (Q34). Les tranches sont remplacées ensemble par
 * SaveRatePlan, qui journalise l'avant et l'après sur la grille.
 *
 * @property int $id
 * @property int $rate_plan_id
 * @property int $min_days
 * @property int|null $max_days
 * @property Money $daily_amount
 */
#[Fillable(['rate_plan_id', 'min_days', 'max_days', 'daily_amount'])]
class RatePlanLine extends Model
{
    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'min_days' => 'integer',
            'max_days' => 'integer',
            'daily_amount' => MoneyCast::class,
        ];
    }

    /**
     * @return BelongsTo<RatePlan, $this>
     */
    public function ratePlan(): BelongsTo
    {
        return $this->belongsTo(RatePlan::class);
    }
}
