<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleFinancing;
use App\Support\Authorization\Permission;

/**
 * Financements (B-11 à B-15) : super admin et admin seulement (fleet.manage), portée par
 * profil et non par agence (architecture §8.3). L'utilisateur d'agence n'y a pas accès.
 * Aucune suppression.
 */
class VehicleFinancingPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FleetManage->value);
    }

    public function view(User $actor, VehicleFinancing $financing): bool
    {
        return $actor->can(Permission::FleetManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::FleetManage->value);
    }

    public function update(User $actor, VehicleFinancing $financing): bool
    {
        return $actor->can(Permission::FleetManage->value);
    }

    public function delete(User $actor, VehicleFinancing $financing): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
