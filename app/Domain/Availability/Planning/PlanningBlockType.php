<?php

declare(strict_types=1);

namespace App\Domain\Availability\Planning;

/**
 * Nature d'une occupation affichée sur le planning (parcours P2, légende des blocs).
 */
enum PlanningBlockType: string
{
    case Reservation = 'reservation';
    case Contract = 'contract';
    case Maintenance = 'maintenance';
    case Unavailability = 'unavailability';

    public function label(): string
    {
        return match ($this) {
            self::Reservation => 'Réservation confirmée',
            self::Contract => 'Contrat en cours',
            self::Maintenance => 'Maintenance',
            self::Unavailability => 'Immobilisation',
        };
    }

    /**
     * Les réservations et les contrats concernent un client : seuls eux portent un nom.
     */
    public function concernsCustomer(): bool
    {
        return $this === self::Reservation || $this === self::Contract;
    }
}
