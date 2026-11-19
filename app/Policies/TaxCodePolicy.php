<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\TaxCode;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Codes fiscaux : liste fermée du référentiel, lue par le super admin et l'admin du
 * siège ; jamais créée, modifiée ni supprimée depuis l'interface.
 */
class TaxCodePolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->can(Permission::FiscalManage->value) || $actor->can(Permission::ReferenceDataManage->value);
    }

    public function view(User $actor, TaxCode $code): bool
    {
        return $this->viewAny($actor);
    }

    public function create(User $actor): bool
    {
        return false;
    }

    public function update(User $actor, TaxCode $code): bool
    {
        return false;
    }

    public function delete(User $actor, TaxCode $code): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
