<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Option;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Options facturables (A-05) : super admin et admin du siège en écriture ; tout profil
 * du back-office les lit (comptoir). Aucune suppression.
 */
class OptionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->profile() !== null;
    }

    public function view(User $actor, Option $option): bool
    {
        return $actor->profile() !== null;
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor, Option $option): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function delete(User $actor, Option $option): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
