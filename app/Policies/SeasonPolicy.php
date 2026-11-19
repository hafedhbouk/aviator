<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Season;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Saisons (A-04) : super admin et admin du siège. Suppression d'une saison inutilisée
 * seulement (DeleteSeason).
 */
class SeasonPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function view(User $actor, Season $season): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor, Season $season): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function delete(User $actor, Season $season): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value) && $season->ratePlans()->doesntExist();
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
