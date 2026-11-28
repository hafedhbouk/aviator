<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\CashSession;
use App\Models\User;

/**
 * Journal de caisse (E-09, Q55, RG-13) : ouverture et clôture par un profil de l'agence ;
 * les caisses et leurs écarts sont visibles de l'admin et du super admin (portée de
 * toutes les agences). Une caisse ne se modifie ni ne se supprime.
 */
class CashSessionPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canActOnAllAgencies() || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, CashSession $session): bool
    {
        return $actor->canActOnAgency($session->agency_id);
    }

    public function open(User $actor, int $agencyId): bool
    {
        return $actor->canActOnAgency($agencyId);
    }

    public function close(User $actor, CashSession $session): bool
    {
        return $session->isOpen() && $actor->canActOnAgency($session->agency_id);
    }

    public function update(User $actor, CashSession $session): bool
    {
        return false;
    }

    public function delete(User $actor, CashSession $session): bool
    {
        return false;
    }
}
