<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaxRule;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Règles de TVA (plan de la phase 3, §2) : super admin en écriture (fiscal.manage),
 * admin du siège en lecture, utilisateur d'agence sans accès. Rien ne se supprime.
 */
class TaxRulePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FiscalManage->value) || $actor->can(Permission::ReferenceDataManage->value);
    }

    public function view(User $actor, TaxRule $rule): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::FiscalManage->value);
    }

    public function update(User $actor, TaxRule $rule): bool
    {
        return $actor->can(Permission::FiscalManage->value);
    }

    public function delete(User $actor, TaxRule $rule): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
