<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Models\Vehicle;
use App\Support\Authorization\Permission;

/**
 * Véhicules (module B) : super admin et admin en écriture (fleet.manage) ; l'utilisateur
 * d'agence lit les véhicules localisés dans ses agences (AgencyScope sur
 * current_agency_id pour les listes, contrôle explicite ici pour la fiche).
 * Aucune suppression : un véhicule sort du parc (lot L5).
 */
class VehiclePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FleetManage->value) || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, Vehicle $vehicle): bool
    {
        return $actor->can(Permission::FleetManage->value) || $actor->canActOnAgency($vehicle->current_agency_id);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::FleetManage->value);
    }

    public function update(User $actor, Vehicle $vehicle): bool
    {
        return $actor->can(Permission::FleetManage->value);
    }

    public function delete(User $actor, Vehicle $vehicle): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
