<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Domain\Setup\RateLines;
use App\Models\Agency;
use App\Models\RatePlan;
use App\Models\User;
use App\Models\VehicleCategory;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Crée ou modifie une grille tarifaire (A-04) et ses tranches, avec les règles suivantes.
 *
 * - Super admin et admin du siège seulement (RatePlanPolicy).
 * - Grille d'une catégorie pour une saison, commune (agence vide) ou propre à une
 *   agence, qui prime alors sur la grille commune (Q10). Catégorie et agence actives.
 * - Validité appliquée au jour loué : du valid_from au valid_to (vide = sans fin).
 *   Un changement de prix en cours de saison = fermer l'ancienne grille, en créer une.
 * - Une seule grille **active** par catégorie, saison et agence sur une même période :
 *   contrôle sous verrou de la ligne de la catégorie.
 * - Tranches validées par RateLines (continues depuis 1 jour, dernière ouverte, HT).
 * - Journal « audit » : la grille par AuditsChanges ; ses tranches par une entrée
 *   « rate_plan_lines_updated » avec l'avant et l'après (J-04 : tarifs).
 */
final class SaveRatePlan
{
    /**
     * @param  array<string, mixed>  $data  vehicle_category_id, season_id, agency_id (vide = commune),
     *                                      valid_from, valid_to, is_active, lines[{min_days, max_days, daily_amount}]
     */
    public function handle(?RatePlan $plan, array $data, User $actor): RatePlan
    {
        if (! $actor->can($plan === null ? 'create' : 'update', $plan ?? RatePlan::class)) {
            throw SetupException::notAllowed('les grilles tarifaires');
        }

        /** @var array{vehicle_category_id: int|string, season_id: int|string, agency_id: int|string|null, valid_from: string, valid_to: string|null} $values */
        $values = Validator::make([
            'vehicle_category_id' => $data['vehicle_category_id'] ?? null,
            'season_id' => $data['season_id'] ?? null,
            'agency_id' => ($data['agency_id'] ?? null) === '' ? null : ($data['agency_id'] ?? null),
            'valid_from' => $data['valid_from'] ?? null,
            'valid_to' => ($data['valid_to'] ?? null) === '' ? null : ($data['valid_to'] ?? null),
        ], [
            'vehicle_category_id' => ['required', 'integer', 'exists:vehicle_categories,id'],
            'season_id' => ['required', 'integer', 'exists:seasons,id'],
            'agency_id' => ['nullable', 'integer', 'exists:agencies,id'],
            'valid_from' => ['required', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
        ], attributes: [
            'vehicle_category_id' => 'catégorie', 'season_id' => 'saison', 'agency_id' => 'agence',
            'valid_from' => 'début de validité', 'valid_to' => 'fin de validité',
        ])->validate();

        $categoryId = (int) $values['vehicle_category_id'];
        $seasonId = (int) $values['season_id'];
        $agencyId = $values['agency_id'] === null ? null : (int) $values['agency_id'];
        $validFrom = $values['valid_from'];
        $validTo = $values['valid_to'];
        $isActive = (bool) ($data['is_active'] ?? true);

        if ($validTo !== null && $validTo < $validFrom) {
            throw SetupException::invalidRatePlanValidity();
        }

        $lines = RateLines::fromInput(is_array($data['lines'] ?? null) ? array_values($data['lines']) : null);

        return DB::transaction(function () use ($plan, $actor, $categoryId, $seasonId, $agencyId, $validFrom, $validTo, $isActive, $lines): RatePlan {
            // Verrou de la catégorie : sérialise les écritures de ses grilles.
            $category = VehicleCategory::query()->lockForUpdate()->findOrFail($categoryId);

            if ($isActive) {
                $this->assertActiveReferences($category, $agencyId);
                $this->assertNoOverlap($plan, $categoryId, $seasonId, $agencyId, $validFrom, $validTo);
            }

            $plan ??= new RatePlan;
            $before = $plan->exists ? $this->currentLines($plan) : [];

            $plan->fill([
                'vehicle_category_id' => $categoryId,
                'season_id' => $seasonId,
                'agency_id' => $agencyId,
                'valid_from' => CarbonImmutable::createFromFormat('Y-m-d', $validFrom)->startOfDay(),
                'valid_to' => $validTo === null ? null : CarbonImmutable::createFromFormat('Y-m-d', $validTo)->startOfDay(),
                'is_active' => $isActive,
            ]);
            $plan->save();

            $after = $lines->toArray();

            if ($before !== $after) {
                $plan->lines()->delete();
                $plan->lines()->createMany(array_map(fn (array $line): array => [
                    'min_days' => $line['min_days'],
                    'max_days' => $line['max_days'],
                    'daily_amount' => $line['daily_amount'],
                ], $after));

                activity('audit')
                    ->causedBy($actor)
                    ->performedOn($plan)
                    ->event('rate_plan_lines_updated')
                    ->withProperties(['old' => $before, 'attributes' => $after])
                    ->log('Tranches de la grille tarifaire modifiées');
            }

            return $plan->refresh();
        });
    }

    private function assertActiveReferences(VehicleCategory $category, ?int $agencyId): void
    {
        if (! $category->is_active) {
            throw SetupException::inactiveReference('catégorie');
        }

        if ($agencyId !== null && ! Agency::query()->whereKey($agencyId)->where('is_active', true)->exists()) {
            throw SetupException::inactiveReference('agence');
        }
    }

    private function assertNoOverlap(?RatePlan $plan, int $categoryId, int $seasonId, ?int $agencyId, string $validFrom, ?string $validTo): void
    {
        $overlap = RatePlan::query()
            ->where('vehicle_category_id', $categoryId)
            ->where('season_id', $seasonId)
            ->when(
                $agencyId === null,
                fn ($query) => $query->whereNull('agency_id'),
                fn ($query) => $query->where('agency_id', $agencyId),
            )
            ->where('is_active', true)
            ->when($plan !== null && $plan->exists, fn ($query) => $query->whereKeyNot($plan?->getKey()))
            // Périodes [from, to] qui se coupent ; une fin vide = sans fin.
            ->when($validTo !== null, fn ($query) => $query->where('valid_from', '<=', $validTo))
            ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $validFrom))
            ->exists();

        if ($overlap) {
            throw SetupException::ratePlanOverlaps();
        }
    }

    /**
     * @return list<array{min_days: int, max_days: int|null, daily_amount: string}>
     */
    private function currentLines(RatePlan $plan): array
    {
        return $plan->lines()->get()->map(fn ($line): array => [
            'min_days' => $line->min_days,
            'max_days' => $line->max_days,
            'daily_amount' => $line->daily_amount->toDecimalString(),
        ])->values()->all();
    }
}
