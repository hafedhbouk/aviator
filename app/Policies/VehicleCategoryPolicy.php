<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleCategory;
use App\Support\Authorization\Permission;

/**
 * Catégories de véhicules (A-03) : super admin et admin du siège en écriture ; tout
 * profil du back-office les lit (comptoir). Aucune suppression.
 */
class VehicleCategoryPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->profile() !== null;
    }

    public function view(User $actor, VehicleCategory $category): bool
    {
        return $actor->profile() !== null;
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor, VehicleCategory $category): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function delete(User $actor, VehicleCategory $category): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
