<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Statut d'un véhicule (B-09, cahier §5.1). Écrit uniquement par
 * App\Domain\Fleet\VehicleStatusService (lot L5) ; « retired » est définitif.
 */
enum VehicleStatus: string
{
    case Available = 'available';
    case Reserved = 'reserved';
    case Rented = 'rented';
    case Maintenance = 'maintenance';
    case Unavailable = 'unavailable';
    case Retired = 'retired';

    public function label(): string
    {
        return match ($this) {
            self::Available => 'Disponible',
            self::Reserved => 'Réservé',
            self::Rented => 'Loué',
            self::Maintenance => 'En maintenance',
            self::Unavailable => 'Indisponible',
            self::Retired => 'Sorti du parc',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Available => 'success',
            self::Reserved => 'info',
            self::Rented => 'primary',
            self::Maintenance => 'warning',
            self::Unavailable => 'danger',
            self::Retired => 'gray',
        };
    }
}
