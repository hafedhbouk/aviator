<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Models\Season;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Crée ou modifie une saison datée (A-04), bornes incluses, avec les règles suivantes.
 *
 * - Super admin et admin du siège seulement (SeasonPolicy).
 * - Aucun chevauchement avec une autre saison : contrôle sous verrou (verrou de
 *   cache atomique, sur la base en production), pour que deux saisies simultanées
 *   ne passent pas toutes les deux.
 * - Modification journalisée (audit).
 */
final class SaveSeason
{
    public const LOCK = 'setup:seasons';

    /**
     * @param  array<string, mixed>  $data  name, starts_on, ends_on (AAAA-MM-JJ)
     */
    public function handle(?Season $season, array $data, User $actor): Season
    {
        if (! $actor->can($season === null ? 'create' : 'update', $season ?? Season::class)) {
            throw SetupException::notAllowed('les saisons');
        }

        /** @var array{name: string, starts_on: string, ends_on: string} $values */
        $values = Validator::make([
            'name' => is_scalar($data['name'] ?? null) ? trim((string) $data['name']) : null,
            'starts_on' => $data['starts_on'] ?? null,
            'ends_on' => $data['ends_on'] ?? null,
        ], [
            'name' => ['required', 'string', 'max:100'],
            'starts_on' => ['required', 'date_format:Y-m-d'],
            'ends_on' => ['required', 'date_format:Y-m-d'],
        ], attributes: ['name' => 'nom', 'starts_on' => 'début', 'ends_on' => 'fin'])->validate();

        $start = CarbonImmutable::createFromFormat('Y-m-d', $values['starts_on'])->startOfDay();
        $end = CarbonImmutable::createFromFormat('Y-m-d', $values['ends_on'])->startOfDay();

        if ($end->lessThan($start)) {
            throw SetupException::invalidSeasonDates();
        }

        return Cache::lock(self::LOCK, 10)->block(5, fn (): Season => DB::transaction(function () use ($season, $values, $start, $end): Season {
            $overlapping = Season::query()
                ->where('starts_on', '<=', $end->toDateString())
                ->where('ends_on', '>=', $start->toDateString())
                ->when($season !== null, fn ($query) => $query->whereKeyNot($season?->getKey()))
                ->first();

            if ($overlapping !== null) {
                throw SetupException::seasonOverlaps($overlapping->label());
            }

            $season ??= new Season;
            $season->fill(['name' => $values['name'], 'starts_on' => $start, 'ends_on' => $end]);
            $season->save();

            return $season;
        }));
    }
}
