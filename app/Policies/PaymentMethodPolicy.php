<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\PaymentMethod;
use App\Models\User;
use App\Support\Authorization\Permission;

/**
 * Modes de paiement (A-07) : super admin et admin en écriture ; tout profil les lit
 * (encaissement au comptoir). Aucune suppression.
 */
class PaymentMethodPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->profile() !== null;
    }

    public function view(User $actor, PaymentMethod $method): bool
    {
        return $actor->profile() !== null;
    }

    public function create(User $actor): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function update(User $actor, PaymentMethod $method): bool
    {
        return $actor->can(Permission::ReferenceDataManage->value);
    }

    public function delete(User $actor, PaymentMethod $method): bool
    {
        return false;
    }

    public function deleteAny(User $actor): bool
    {
        return false;
    }
}
