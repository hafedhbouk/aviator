<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\VehicleDocument;
use App\Support\Authorization\Permission;

/**
 * Documents des véhicules (B-05) : lus comme le véhicule, écrits par fleet.manage.
 * Aucune suppression : l'historique des échéances est conservé.
 */
class VehicleDocumentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FleetManage->value) || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, VehicleDocument $document): bool
    {
        $vehicle = $document->vehicle()->withoutGlobalScopes()->first();

        return $vehicle !== null && $actor->can('view', $vehicle);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::FleetManage->value);
    }

    public function update(User $actor, VehicleDocument $document): bool
    {
        return $actor->can(Permission::FleetManage->value);
    }

    public function delete(User $actor, VehicleDocument $document): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
