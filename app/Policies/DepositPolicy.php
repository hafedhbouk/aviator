<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contract;
use App\Models\Deposit;
use App\Models\User;

/**
 * Cautions (module F, RG-13) : réception, restitution et demande de retenue par un profil
 * de l'agence du contrat ; la retenue elle-même est décidée par le super admin (D46).
 * Une caution ne se modifie ni ne se supprime.
 */
class DepositPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canActOnAllAgencies() || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, Deposit $deposit): bool
    {
        return $actor->canActOnAgency($deposit->agency_id);
    }

    public function create(User $actor, ?Contract $contract = null): bool
    {
        return $contract === null ? $this->viewAny($actor) : $actor->canActOnAgency($contract->agency_id);
    }

    public function refund(User $actor, Deposit $deposit): bool
    {
        return $actor->canActOnAgency($deposit->agency_id);
    }

    public function retain(User $actor, Deposit $deposit): bool
    {
        return $actor->canActOnAgency($deposit->agency_id);
    }

    public function update(User $actor, Deposit $deposit): bool
    {
        return false;
    }

    public function delete(User $actor, Deposit $deposit): bool
    {
        return false;
    }
}
