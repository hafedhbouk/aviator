<?php

declare(strict_types=1);

namespace App\Domain\Setup\Actions;

use App\Domain\Setup\Exceptions\SetupException;
use App\Models\Season;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Supprime une saison saisie par erreur, seulement si aucune grille ne l'utilise
 * (la clé étrangère RESTRICT le garantit aussi). Suppression journalisée (audit).
 */
final class DeleteSeason
{
    public function handle(Season $season, User $actor): void
    {
        if (! $actor->can('update', $season)) {
            throw SetupException::notAllowed('les saisons');
        }

        DB::transaction(function () use ($season): void {
            $locked = Season::query()->lockForUpdate()->findOrFail($season->getKey());

            if ($locked->ratePlans()->exists()) {
                throw SetupException::seasonInUse();
            }

            $locked->delete();
        });
    }
}
