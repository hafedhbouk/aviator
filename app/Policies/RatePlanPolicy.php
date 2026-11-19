<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\RatePlan;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Grilles tarifaires (A-04) : super admin et admin du siège. Aucune suppression : une
 * grille se ferme (fin de validité) ou se désactive.
 */
class RatePlanPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function view(User $actor, RatePlan $plan): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor, RatePlan $plan): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function delete(User $actor, RatePlan $plan): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
