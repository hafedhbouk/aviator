<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Contract;
use App\Models\User;
use App\Policies\Concerns\ForbidsDeletingNumberedDocuments;

/**
 * Contrats (module D, RG-13) : chaque profil agit sur les contrats des agences où il
 * agit (toutes pour le super admin et l'admin ; les siennes pour l'utilisateur
 * d'agence). Aucune suppression d'un contrat numéroté : il s'annule (RG-11).
 */
class ContractPolicy
{
    use ForbidsDeletingNumberedDocuments;

    public function viewAny(User $actor): bool
    {
        return $actor->canActOnAllAgencies() || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, Contract $contract): bool
    {
        return $actor->canActOnAgency($contract->agency_id);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, Contract $contract): bool
    {
        return $actor->canActOnAgency($contract->agency_id);
    }
}
