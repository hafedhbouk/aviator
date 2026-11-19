<?php

declare(strict_types=1);

namespace App\Domain\Setup;

use App\Models\RatePlan;
use App\Models\VehicleCategory;
use App\Support\Money\Money;
use DateTimeInterface;

/**
 * Prix journalier HT d'un jour loué (A-04, Q10, Q33) : grille active de la catégorie
 * pour la saison qui contient ce jour et valide ce jour-là ; la grille de l'agence
 * prime sur la grille commune. La tranche est celle de la durée totale de la location
 * (tarif dégressif appliqué à tous les jours). Null = pas de prix : la réservation
 * sera refusée (phase 4), jamais tarifée à zéro.
 *
 * Le calcul du prix d'une réservation (décompte des jours, Q32 ; jours à cheval sur
 * deux saisons, Q33) relève de la phase 4 et s'appuiera sur ce service.
 */
final class RateResolver
{
    public function dailyAmount(VehicleCategory|int $category, ?int $agencyId, DateTimeInterface $day, int $rentalDays): ?Money
    {
        $plan = $this->planFor($category, $agencyId, $day);

        if ($plan === null) {
            return null;
        }

        return RateLines::fromInput($plan->lines->map(fn ($line): array => [
            'min_days' => $line->min_days,
            'max_days' => $line->max_days,
            'daily_amount' => $line->daily_amount,
        ])->all())->dailyAmountFor($rentalDays);
    }

    public function planFor(VehicleCategory|int $category, ?int $agencyId, DateTimeInterface $day): ?RatePlan
    {
        $categoryId = $category instanceof VehicleCategory ? $category->id : $category;
        $date = $day->format('Y-m-d');

        $plans = RatePlan::query()
            ->with('lines')
            ->where('vehicle_category_id', $categoryId)
            ->where('is_active', true)
            ->where('valid_from', '<=', $date)
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $date))
            ->whereHas('season', fn ($query) => $query->where('starts_on', '<=', $date)->where('ends_on', '>=', $date))
            ->where(fn ($query) => $query->whereNull('agency_id')->when(
                $agencyId !== null,
                fn ($query) => $query->orWhere('agency_id', $agencyId),
            ))
            ->get();

        return $plans->firstWhere('agency_id', $agencyId) ?? $plans->firstWhere('agency_id', null);
    }
}
