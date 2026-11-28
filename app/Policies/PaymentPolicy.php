<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Payment;
use App\Models\User;

/**
 * Encaissements (module E, RG-13) : chaque profil agit sur les encaissements des agences
 * où il agit ; un encaissement ne se supprime jamais, il s'annule avec motif (RG-11).
 * La matrice complète des droits de la phase 6 est vérifiée au lot L15.
 */
class PaymentPolicy
{
    public function viewAny(User $actor): bool
    {
        return $actor->canActOnAllAgencies() || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, Payment $payment): bool
    {
        return $actor->canActOnAgency($payment->agency_id);
    }

    /**
     * Encaissement reçu dans une agence où le profil agit.
     */
    public function create(User $actor, ?int $agencyId = null): bool
    {
        return $agencyId === null ? $this->viewAny($actor) : $actor->canActOnAgency($agencyId);
    }

    public function allocate(User $actor, Payment $payment): bool
    {
        return ! $payment->isCancelled() && $actor->canActOnAgency($payment->agency_id);
    }

    public function cancel(User $actor, Payment $payment): bool
    {
        return ! $payment->isCancelled() && $actor->canActOnAgency($payment->agency_id);
    }

    public function update(User $actor, Payment $payment): bool
    {
        return false;
    }

    public function delete(User $actor, Payment $payment): bool
    {
        return false;
    }
}
