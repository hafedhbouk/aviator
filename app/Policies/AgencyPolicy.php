<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Agency;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Agences (A-02, plan de la phase 3 §2) : super admin et admin du siège en écriture ;
 * l'utilisateur d'agence consulte ses agences autorisées (agence par défaut et
 * remplacements en cours), pour leurs lieux et horaires. Aucune suppression.
 */
class AgencyPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value) || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, Agency $agency): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value) || $actor->canActOnAgency($agency->id);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor, Agency $agency): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function delete(User $actor, Agency $agency): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
