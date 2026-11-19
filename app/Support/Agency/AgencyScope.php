<?php

declare(strict_types=1);

namespace App\Support\Agency;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Support\Facades\Auth;

/**
 * Portée par agence (RG-13) : un utilisateur d'agence ne lit que les documents de
 * ses agences (agence par défaut et agences de remplacement, Q69).
 *
 * - Sans effet pour le super admin et l'admin (permission agencies.all).
 * - Sans effet hors d'une requête authentifiée (commandes, tâches planifiées, file).
 * - Un utilisateur sans agence ne voit rien.
 *
 * Le scope est une première barrière : les policies vérifient l'agence même s'il
 * est contourné (withoutGlobalScopes), et BelongsToAgency refuse toute écriture
 * hors des agences autorisées.
 *
 * @template TModel of Model
 *
 * @implements Scope<TModel>
 */
final class AgencyScope implements Scope
{
    /**
     * @param  string  $column  colonne de l'agence : « agency_id » pour un document ;
     *                          « current_agency_id » pour un véhicule, lu par l'agence
     *                          où il se trouve (architecture §8.3)
     */
    public function __construct(private readonly string $column = 'agency_id') {}

    /**
     * @param  Builder<covariant TModel>  $builder
     * @param  TModel  $model
     */
    public function apply(Builder $builder, Model $model): void
    {
        $user = Auth::user();

        if (! $user instanceof User || $user->canActOnAllAgencies()) {
            return;
        }

        // Sans agence autorisée, whereIn([]) ne renvoie aucune ligne (Laravel produit « 0 = 1 »).
        $builder->whereIn($model->qualifyColumn($this->column), $user->authorizedAgencyIds());
    }
}
