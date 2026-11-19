<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Supplier;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Fournisseurs (G-05) : super admin et admin du siège seulement. Aucune suppression.
 */
class SupplierPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function view(User $actor, Supplier $supplier): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor, Supplier $supplier): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function delete(User $actor, Supplier $supplier): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
