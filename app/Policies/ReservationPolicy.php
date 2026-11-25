<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Reservation;
use App\Models\User;
use App\Policies\Concerns\ForbidsDeletingNumberedDocuments;

/**
 * Réservations (module C, RG-13) : chaque profil agit sur les réservations des
 * agences où il agit (toutes pour le super admin et l'admin ; les siennes pour
 * l'utilisateur d'agence, Q37 : seule l'agence de départ crée). Aucune suppression
 * d'une réservation numérotée : elle s'annule (RG-11).
 */
class ReservationPolicy
{
    use ForbidsDeletingNumberedDocuments;

    public function viewAny(User $actor): bool
    {
        return $actor->canActOnAllAgencies() || $actor->authorizedAgencyIds() !== [];
    }

    public function view(User $actor, Reservation $reservation): bool
    {
        return $actor->canActOnAgency($reservation->agency_id);
    }

    public function create(User $actor): bool
    {
        return $this->viewAny($actor);
    }

    public function update(User $actor, Reservation $reservation): bool
    {
        return $actor->canActOnAgency($reservation->agency_id);
    }
}
