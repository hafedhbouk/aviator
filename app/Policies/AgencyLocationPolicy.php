<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\AgencyLocation;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Lieux de prise en charge et de restitution (A-02) : super admin et admin du siège
 * en écriture ; l'utilisateur d'agence lit les lieux de ses agences (AgencyScope
 * pour les listes, contrôle explicite ici pour la fiche). Aucune suppression.
 */
class AgencyLocationPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value) || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, AgencyLocation $location): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value) || $actor->canActOnAgency($location->agency_id);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor, AgencyLocation $location): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function delete(User $actor, AgencyLocation $location): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
