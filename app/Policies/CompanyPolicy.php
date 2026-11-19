<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Fiche société (A-01, plan de la phase 3 §2) : super admin et admin du siège en
 * écriture ; utilisateurs d'agence sans accès. Les mentions des factures relèvent
 * des paramètres fiscaux : super admin uniquement, lecture pour l'admin. La fiche
 * ne se supprime pas.
 */
class CompanyPolicy
{
    public function view(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function updateLegalMentions(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value)
            && $actor->can(Permission::FiscalManage->value);
    }

    public function delete(User $actor): bool
    {
        return false;
    }
}
