<?php

declare(strict_types=1);

namespace App\Policies\Concerns;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * Contrôle d'agence des policies (RG-13), indépendant d'AgencyScope : il s'applique
 * même à un document chargé sans le scope (withoutGlobalScopes).
 */
trait ChecksAgency
{
    protected function withinAgency(User $user, Model $record): bool
    {
        $agencyId = $record->getAttribute('agency_id');

        return $user->canActOnAgency(is_numeric($agencyId) ? (int) $agencyId : null);
    }
}
